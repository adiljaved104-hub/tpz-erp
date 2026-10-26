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
        $width = min(2400, $size[0]);
        $height = max(1, (int) round($size[1] * $width / $size[0]));
        $memory = trim((string) ini_get('memory_limit'));
        $budget = (float) $memory * match (strtolower(substr($memory, -1))) {
            'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1,
        };
        if ($budget > 0 && memory_get_usage(true) + ($size[0] * $size[1] + max(700, $width) * ($height + 120)) * 4
            + strlen($original) * 2 + 20971520 > $budget) {
            throw new \RuntimeException('Evidence photo exceeds the safe labeling memory budget.');
        }
        $source = imagecreatefromstring($original);
        if (! $source) {
            throw new \RuntimeException('Evidence photo could not be decoded.');
        }
        $canvas = imagecreatetruecolor(max(700, $width), $height + 120);
        $dark = imagecolorallocate($canvas, 15, 30, 48);
        imagefill($canvas, 0, 0, $dark);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $return = $evidence->finding->item->customerReturn;
        $lines = [
            'TECH POINT ZONE - CUSTOMER RETURN EVIDENCE',
            'Return '.$return->reference.' | Order '.($return->order?->reference ?? $return->order_id),
            'Device-reported capture '.$evidence->captured_at_device->utc()->format('Y-m-d H:i:s').' UTC',
            'Reported time zone '.$evidence->capture_timezone.' | Server received '.$evidence->uploaded_at->utc()->format('Y-m-d H:i:s').' UTC',
        ];
        foreach ($lines as $index => $line) {
            imagestring($canvas, 4, 12, $height + 8 + $index * 27, $line, $white);
        }
        ob_start();
        imagejpeg($canvas, null, 93);
        $bytes = (string) ob_get_clean();
        imagedestroy($source);
        imagedestroy($canvas);

        return $bytes;
    }

    private function video(CustomerReturnEvidence $evidence, string $binary): string
    {
        $return = $evidence->finding->item->customerReturn;
        $reference = preg_replace('/[^A-Za-z0-9-]/', '', $return->reference);
        $orderReference = preg_replace('/[^A-Za-z0-9-]/', '', $return->order?->reference ?? (string) $return->order_id);
        $static = 'TECH POINT ZONE - RETURN EVIDENCE - RETURN '.$reference.' - ORDER '.$orderReference;
        $epoch = $evidence->captured_at_device->getTimestamp();
        $zone = preg_replace('/[^A-Za-z0-9_\/-]/', '', (string) $evidence->capture_timezone);
        // PTS starts at zero; the supplied offset is the reported capture epoch.
        // No shell is involved, and the UTC value advances on every video frame.
        $filter = $this->videoFilter($static, $epoch, $zone);
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
        $process = new Process([$binary, '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-i', Storage::disk('local')->path($evidence->original_path), '-vf', $filter,
            '-map', '0:v:0', '-map', '0:a?',
            '-c:v', 'libx264', '-crf', '18', '-preset', 'medium', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '192k', '-movflags', '+faststart', '-f', 'mp4', $output]);
        $process->setTimeout(1500);
        $process->run();
        clearstatcache(true, $output);
        if (! $process->isSuccessful() || filesize($output) === 0) {
            unlink($output);
            throw new \RuntimeException('Video label generation failed.');
        }

        return $output;
    }

    private function videoFilter(string $static, int $epoch, string $zone): string
    {
        // A drawtext option embedded in a filtergraph needs two escaping
        // levels for the colons inside %{pts:gmtime:offset}.
        $ptsSeparator = '\\\\:';

        return 'setpts=PTS-STARTPTS,drawtext=text='.$static.':fontcolor=white:fontsize=24:box=1:boxcolor=black@0.7:x=20:y=20,'
            .'drawtext=text=DEVICE-REPORTED CAPTURE %{pts'.$ptsSeparator.'gmtime'.$ptsSeparator.$epoch.'} UTC ('.$zone.'):fontcolor=white:fontsize=24:box=1:boxcolor=black@0.7:x=20:y=55';
    }
}
