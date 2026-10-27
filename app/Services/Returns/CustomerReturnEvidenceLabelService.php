<?php

namespace App\Services\Returns;

use App\Models\CustomerReturnEvidence;
use App\Models\CustomerReturnEvidenceLabel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class CustomerReturnEvidenceLabelService
{
    public function createIfSupported(CustomerReturnEvidence $evidence, bool $strict = false): ?CustomerReturnEvidenceLabel
    {
        if ($evidence->captured_at_device === null) {
            return null; // A capture time must never be inferred from upload time.
        }
        if ($label = $evidence->label()->first()) {
            return $label;
        }
        $binary = (string) config('customer_return_evidence.ffmpeg_binary');
        if ($evidence->media_type === 'video' && $binary === '') {
            return null; // The original remains available; never substitute an unlabeled claim copy.
        }
        $path = 'customer-return-evidence/labels/'.Str::uuid().($evidence->media_type === 'video' ? '.mp4' : '.jpg');
        $temporary = null;
        try {
            if ($evidence->media_type === 'video') {
                $temporary = $this->video($evidence, $binary);
                $stream = fopen($temporary, 'rb');
                if ($stream === false) {
                    throw new \RuntimeException('Labeled video could not be read.');
                }
                try {
                    $stored = Storage::disk('local')->put($path, $stream);
                } finally {
                    fclose($stream);
                }
                $checksum = hash_file('sha256', $temporary);
            } else {
                $bytes = $this->photo($evidence);
                $stored = Storage::disk('local')->put($path, $bytes);
                $checksum = hash('sha256', $bytes);
            }
            if (! $stored) {
                throw new \RuntimeException('Labeled evidence storage failed.');
            }
            $label = $evidence->label()->firstOrCreate([], [
                'path' => $path, 'sha256' => $checksum,
                'mime_type' => $evidence->media_type === 'video' ? 'video/mp4' : 'image/jpeg',
                'created_at' => now(),
            ]);
            if ($label->path !== $path) {
                Storage::disk('local')->delete($path);
            }

            return $label;
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            Log::warning('Return evidence label is pending.', [
                'evidence_id' => $evidence->id, 'exception' => $exception::class,
            ]);
            if ($strict) {
                throw $exception;
            }

            return null;
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function photo(CustomerReturnEvidence $evidence): string
    {
        $original = Storage::disk('local')->get($evidence->original_path);
        $size = getimagesizefromstring($original);
        if (! $size || $size[0] * $size[1] > 16000000) {
            throw new \RuntimeException('Evidence photo exceeds the safe labeling dimensions.');
        }
        [$width, $height] = $size;
        $memory = trim((string) ini_get('memory_limit'));
        $budget = (float) $memory * match (strtolower(substr($memory, -1))) {
            'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1,
        };
        if ($budget > 0 && memory_get_usage(true) + $width * $height * 8
            + strlen($original) * 2 + 20971520 > $budget) {
            throw new \RuntimeException('Evidence photo exceeds the safe labeling memory budget.');
        }
        $source = imagecreatefromstring($original);
        if (! $source) {
            throw new \RuntimeException('Evidence photo could not be decoded.');
        }
        try {
            $layout = $this->layout($evidence, $width, $height);
            imagealphablending($source, true);
            $band = imagecolorallocatealpha($source, 0, 0, 0, 97); // ~24% opaque, behind text only.
            imagefilledrectangle($source, 0, 0, $layout['topWidth'] - 1, $layout['topHeight'] - 1, $band);
            imagefilledrectangle($source, 0, $height - $layout['footerHeight'], $layout['footerWidth'] - 1, $height - 1, $band);
            $this->photoText($source, $layout['stamp'], $layout['stampSize'], $layout['margin'] + $layout['stampSize'], $layout);
            $this->photoText($source, $layout['note'], $layout['noteSize'],
                $layout['margin'] + $layout['stampSize'] + $layout['noteSize'] + 4, $layout);
            $footerTop = $height - $layout['footerHeight'];
            foreach ($layout['lines'] as $index => $line) {
                $this->photoText($source, $line, $layout['fontSize'],
                    $footerTop + $layout['margin'] + $layout['fontSize'] + $index * $layout['lineHeight'], $layout);
            }
            ob_start();
            if (! imagejpeg($source, null, 93)) {
                ob_end_clean();
                throw new \RuntimeException('Evidence photo label encoding failed.');
            }

            return (string) ob_get_clean();
        } finally {
            imagedestroy($source);
        }
    }

    private function video(CustomerReturnEvidence $evidence, string $binary): string
    {
        // A five-minute derivative can be large. Keep it on the private
        // evidence disk rather than an unknown or size-limited system /tmp.
        $disk = Storage::disk('local');
        $temporaryDirectory = 'customer-return-evidence/labels/tmp';
        if (! $disk->directoryExists($temporaryDirectory) && ! $disk->makeDirectory($temporaryDirectory)) {
            throw new \RuntimeException('No private temporary media directory available.');
        }
        $output = tempnam($disk->path($temporaryDirectory), 'tpz-evidence-');
        if ($output === false) {
            throw new \RuntimeException('No temporary media path available.');
        }
        $clockFile = 'clock-'.Str::uuid().'.txt';
        $lineFiles = [];
        try {
            $original = $disk->path($evidence->original_path);
            [$width, $height] = $this->videoDimensions($binary, $original);
            $layout = $this->layout($evidence, $width, $height);
            // Using textfile avoids another filtergraph escaping level for the
            // strftime colons. The epoch is device-reported, never server time.
            $clock = '%{pts:gmtime:'.$layout['wallEpoch'].':%d-%m-%Y %a %H\\:%M\\:%S} '.$layout['abbreviation'];
            if (file_put_contents($disk->path($temporaryDirectory).DIRECTORY_SEPARATOR.$clockFile, $clock) === false) {
                throw new \RuntimeException('Could not prepare the private evidence clock.');
            }
            foreach ($layout['lines'] as $line) {
                $name = 'line-'.Str::uuid().'.txt';
                $lineFiles[] = $name;
                if (file_put_contents($disk->path($temporaryDirectory).DIRECTORY_SEPARATOR.$name, $line) === false) {
                    throw new \RuntimeException('Could not prepare the private evidence reference.');
                }
            }
            $filter = $this->videoFilter($layout, $width, $height, $clockFile, $lineFiles);
            $process = new Process([$binary, '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
                '-i', $original, '-vf', $filter, '-map', '0:v:0', '-map', '0:a?',
                '-c:v', 'libx264', '-crf', '18', '-preset', 'medium', '-pix_fmt', 'yuv420p',
                '-c:a', 'aac', '-b:a', '192k', '-movflags', '+faststart', '-f', 'mp4', $output],
                $disk->path($temporaryDirectory));
            $process->setTimeout(1500);
            $process->run();
            clearstatcache(true, $output);
            if (! $process->isSuccessful() || filesize($output) === 0) {
                throw new \RuntimeException('Video label generation failed.');
            }

            return $output;
        } catch (Throwable $exception) {
            unlink($output);
            throw $exception;
        } finally {
            @unlink($disk->path($temporaryDirectory).DIRECTORY_SEPARATOR.$clockFile);
            foreach ($lineFiles as $name) {
                @unlink($disk->path($temporaryDirectory).DIRECTORY_SEPARATOR.$name);
            }
        }
    }

    private function videoDimensions(string $binary, string $original): array
    {
        $probe = dirname($binary).DIRECTORY_SEPARATOR.(str_ends_with(strtolower($binary), '.exe') ? 'ffprobe.exe' : 'ffprobe');
        if (! is_file($probe)) {
            throw new \RuntimeException('The video dimension probe is unavailable.');
        }
        $process = new Process([$probe, '-v', 'error', '-select_streams', 'v:0',
            '-show_entries', 'stream=width,height:stream_side_data=rotation', '-of', 'json', $original]);
        $process->setTimeout(30);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new \RuntimeException('Video dimensions could not be verified.');
        }

        return $this->parseVideoDimensions($process->getOutput());
    }

    private function parseVideoDimensions(string $output): array
    {
        $data = json_decode($output, true);
        $stream = $data['streams'][0] ?? null;
        $width = $stream['width'] ?? null;
        $height = $stream['height'] ?? null;
        if (! is_int($width) || ! is_int($height) || $width < 16 || $height < 16
            || $width > 16000 || $height > 16000) {
            throw new \RuntimeException('Video dimensions could not be verified.');
        }

        // Android MP4s may store landscape-coded frames with a 90-degree
        // display matrix. FFmpeg autorotates before applying the label filter.
        foreach ($stream['side_data_list'] ?? [] as $sideData) {
            $rotation = $sideData['rotation'] ?? null;
            if (is_numeric($rotation) && abs((int) $rotation) % 180 === 90) {
                return [$height, $width];
            }
        }

        return [$width, $height];
    }

    protected function boldFont(): string
    {
        foreach (['/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', 'C:/Windows/Fonts/arialbd.ttf'] as $path) {
            if (is_file($path) && is_readable($path)) {
                return $path;
            }
        }

        throw new \RuntimeException('A readable bold evidence font is unavailable.');
    }

    private function layout(CustomerReturnEvidence $evidence, int $width, int $height): array
    {
        $font = $this->boldFont();
        $margin = max(10, (int) floor(min($width, $height) * .025));
        $fontSize = max(10, min(88, (int) floor(min($width / 22, $height / 24))));
        $return = $evidence->finding->item->customerReturn->loadMissing('order.platform', 'platform');
        $reference = $this->safeReference((string) $return->reference);
        $external = $this->safeReference((string) ($return->order?->external_order_number ?? ''));
        $marketplace = $this->safeReference((string) ($return->platform?->name ?? $return->order?->platform?->name ?? ''));
        $order = $external !== '' ? $external : $this->safeReference((string) ($return->order?->reference ?? $return->order_id));
        $orderPrefix = $external !== ''
            ? ($marketplace !== '' ? $marketplace.' Order ID: ' : 'Marketplace Order ID: ')
            : 'ERP Order ID: ';
        // Leave room for the two-pixel FFmpeg outline and the GD shadow.
        $available = $width - 2 * $margin - 4;
        do {
            $lines = ['TECH POINT ZONE', 'DISPATCH & RETURN EVIDENCE',
                ...$this->wrappedReference('Return ID: ', $reference, $available, $fontSize, $font),
                ...$this->wrappedReference($orderPrefix, $order, $available, $fontSize, $font)];
            $lineHeight = (int) ceil($fontSize * 1.24);
            $footerHeight = count($lines) * $lineHeight + 2 * $margin;
            $linesFit = true;
            foreach ($lines as $line) {
                if ($this->textWidth($line, $fontSize, $font) > $available) {
                    $linesFit = false;
                    break;
                }
            }
            if (($footerHeight <= $height * .32 && $linesFit) || $fontSize === 10) {
                break;
            }
            $fontSize--;
        } while (true);
        if ($footerHeight > $height * .32) {
            throw new \RuntimeException('Evidence references cannot fit without covering the image.');
        }
        $zone = (string) ($evidence->capture_timezone ?: 'UTC');
        $captured = $evidence->deviceCaptureTime();
        $note = 'DEVICE-REPORTED TIME | '.$zone;
        $stampSize = max(12, (int) floor($fontSize * 1.15));
        while ($stampSize > 12 && $this->textWidth($captured->format('d-m-Y D H:i:s T'), $stampSize, $font) > $available) {
            $stampSize--;
        }
        $noteSize = max(8, (int) floor($fontSize * .48));
        while ($noteSize > 8 && $this->textWidth($note, $noteSize, $font) > $available) {
            $noteSize--;
        }
        if ($this->textWidth($note, $noteSize, $font) > $available
            || $this->textWidth($captured->format('d-m-Y D H:i:s T'), $stampSize, $font) > $available) {
            throw new \RuntimeException('The evidence clock cannot fit inside the media margins.');
        }
        foreach ($lines as $line) {
            if ($this->textWidth($line, $fontSize, $font) > $available) {
                throw new \RuntimeException('The evidence references cannot fit inside the media margins.');
            }
        }
        $topHeight = $stampSize + $noteSize + 3 * $margin + 4;
        $topWidth = max($this->textWidth($captured->format('d-m-Y D H:i:s T'), $stampSize, $font),
            $this->textWidth($note, $noteSize, $font)) + 2 * $margin + 4;
        $footerWidth = max(array_map(fn (string $line): int => $this->textWidth($line, $fontSize, $font), $lines)) + 2 * $margin + 4;
        if ($topHeight + $footerHeight >= $height) {
            throw new \RuntimeException('Evidence overlays cannot fit the media safely.');
        }

        return compact('font', 'margin', 'fontSize', 'stampSize', 'lineHeight', 'footerHeight', 'footerWidth', 'topHeight', 'topWidth', 'lines', 'note', 'noteSize') + [
            'stamp' => $captured->format('d-m-Y D H:i:s T'),
            'abbreviation' => $captured->format('T'),
            'wallEpoch' => $captured->getTimestamp() + $captured->getOffset(),
        ];
    }

    private function safeReference(string $reference): string
    {
        // Retain marketplace punctuation while removing invisible controls.
        return trim((string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $reference));
    }

    private function wrappedReference(string $prefix, string $value, int $available, int $fontSize, string $font): array
    {
        $lines = [];
        $line = $prefix;
        foreach (mb_str_split($value) as $character) {
            $candidate = $line.$character;
            if ($this->textWidth($candidate, $fontSize, $font) > $available && $line !== $prefix) {
                $lines[] = rtrim($line);
                $line = '  '.$character;
            } else {
                $line = $candidate;
            }
        }
        $lines[] = rtrim($line);

        return $lines;
    }

    private function textWidth(string $text, int $size, string $font): int
    {
        $box = imagettfbbox($size, 0, $font, $text);
        if ($box === false) {
            throw new \RuntimeException('Evidence text could not be measured.');
        }

        return max($box[2], $box[4]) - min($box[0], $box[6]);
    }

    private function photoText(\GdImage $image, string $text, int $size, int $baseline, array $layout): void
    {
        $shadow = imagecolorallocatealpha($image, 0, 0, 0, 10);
        $white = imagecolorallocate($image, 255, 255, 255);
        foreach ([[-1, 0], [1, 0], [0, -1], [0, 2]] as [$dx, $dy]) {
            imagettftext($image, $size, 0, $layout['margin'] + $dx, $baseline + $dy, $shadow, $layout['font'], $text);
        }
        imagettftext($image, $size, 0, $layout['margin'], $baseline, $white, $layout['font'], $text);
    }

    private function videoFilter(array $layout, int $width, int $height, string $clockFile, array $lineFiles): string
    {
        // Font paths and visible text are controlled server-side. FFmpeg is
        // invoked as an argument vector, not through a shell.
        $font = str_replace(':', '\\\\:', str_replace('\\', '/', $layout['font']));
        $common = 'fontfile='.$font.':fontcolor=white:borderw=2:bordercolor=black@0.8:shadowx=1:shadowy=2:shadowcolor=black@0.8';
        $margin = $layout['margin'];
        $filter = 'setpts=PTS-STARTPTS'
            .',drawbox=x=0:y=0:w='.$layout['topWidth'].':h='.$layout['topHeight'].':color=black@0.24:t=fill'
            .',drawbox=x=0:y=ih-'.$layout['footerHeight'].':w='.$layout['footerWidth'].':h='.$layout['footerHeight'].':color=black@0.24:t=fill'
            .',drawtext='.$common.':textfile='.$clockFile.':fontsize='.$layout['stampSize'].':x='.$margin.':y='.$margin
            .',drawtext='.$common.':text='.$layout['note'].':fontsize='.$layout['noteSize'].':x='.$margin.':y='.($margin + $layout['stampSize'] + 4);
        $footerTop = $height - $layout['footerHeight'];
        if (count($lineFiles) !== count($layout['lines'])) {
            throw new \RuntimeException('The video evidence references are incomplete.');
        }
        foreach ($lineFiles as $index => $name) {
            if (! preg_match('/^[a-zA-Z0-9._-]+$/', $name)) {
                throw new \RuntimeException('The video evidence reference filename is invalid.');
            }
            $filter .= ',drawtext='.$common.':textfile='.$name.':fontsize='.$layout['fontSize']
                .':x='.$margin.':y='.($footerTop + $margin + $index * $layout['lineHeight']);
        }

        return $filter;
    }
}
