<?php

namespace App\Services\Qc;

use App\Enums\QcPermission;
use App\Models\QcEvidence;
use App\Models\QcInspection;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\QcAuthorization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class QcEvidenceService
{
    public const KINDS = ['serial' => 'Serial / IMEI', 'physical' => 'Physical Condition', 'display' => 'Screen On / Display', 'system' => 'System / Health / Final Specification', 'upgrade' => 'Upgrade / Final Configuration', 'additional' => 'Additional Evidence'];

    public function requiredKinds(QcInspection $inspection, ?array $final = null): array
    {
        return ['serial', 'physical', 'display', 'system', ...(app(QcInspectionService::class)->hasUpgrade($inspection, $final) ? ['upgrade'] : [])];
    }

    public function progress(QcInspection $inspection, ?array $final = null): array
    {
        $required = $this->requiredKinds($inspection, $final);
        $proofs = $inspection->evidence()->get();
        $complete = array_values(array_filter($required, fn ($kind) => $proofs->contains(fn ($proof) => $proof->kind === $kind && $proof->customer_visible && Storage::disk('local')->exists($proof->original_path) && Storage::disk('local')->exists($proof->customer_path))));

        return ['required' => $required, 'complete' => $complete, 'missing' => array_values(array_diff($required, $complete)), 'proofs' => $proofs];
    }

    public function uploadMany(QcInspection $inspection, array $files, string $kind, bool $customerVisible, User $actor): array
    {
        return $this->uploadBatch($inspection, [$kind => $files], $customerVisible, $actor);
    }

    public function uploadBatch(QcInspection $inspection, array $categories, bool $additionalVisible, User $actor): array
    {
        app(QcAuthorization::class)->authorize($actor, QcPermission::Update, $inspection);
        Validator::make(['categories' => $categories], ['categories' => ['required', 'array', 'min:1', 'max:6'], 'categories.*' => ['required', 'array', 'min:1', 'max:10']], ['categories.required' => 'Choose at least one evidence photo before uploading.', 'categories.min' => 'Choose at least one evidence photo before uploading.', 'categories.*.max' => 'Choose no more than 10 photos per category per submission.'])->validate();
        foreach ($categories as $kind => $files) {
            Validator::make(['kind' => $kind], ['kind' => ['in:'.implode(',', array_keys(self::KINDS))]])->validate();
            foreach ($files as $index => $file) {
                try {
                    $this->validateFile($file);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(['categories.'.$kind.'.'.$index => self::KINDS[$kind].' photo '.($index + 1).': '.collect($exception->errors())->flatten()->first()]);
                }
            }
        }
        $saved = [];
        try {
            return DB::transaction(function () use ($inspection, $categories, $additionalVisible, $actor, &$saved): array {
                $inspection = app(QcInspectionService::class)->editable($inspection);
                foreach ($categories as $kind => $files) {
                    foreach ($files as $file) {
                        $saved[] = $this->upload($inspection, $file, $kind, $additionalVisible, $actor);
                    }
                }

                return $saved;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete(collect($saved)->flatMap(fn ($proof) => [$proof->original_path, $proof->customer_path])->all());
            throw $exception;
        }
    }

    private function validateFile(mixed $file): void
    {
        Validator::make(['file' => $file], ['file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192', 'dimensions:max_width=5000,max_height=5000']])->validate();
        $info = getimagesize($file->getRealPath());
        if (! $info || $info[0] * $info[1] > 16000000) {
            throw ValidationException::withMessages(['file' => 'Use an image no larger than 16 megapixels.']);
        }
    }

    public function upload(QcInspection $inspection, UploadedFile $file, string $kind, bool $customerVisible, User $actor): QcEvidence
    {
        app(QcAuthorization::class)->authorize($actor, QcPermission::Update, $inspection);
        Validator::make(['kind' => $kind], ['kind' => ['required', 'in:'.implode(',', array_keys(self::KINDS))]])->validate();
        $this->validateFile($file);
        $customerVisible = $kind === 'additional' ? $customerVisible : true;
        $id = (string) str()->uuid();
        $original = 'qc/originals/'.$id.'.bin';
        $customer = 'qc/customer/'.$id.'.jpg';
        $bytes = file_get_contents($file->getRealPath());
        $timestamp = now();
        $paths = [];
        try {
            return DB::transaction(function () use ($inspection, $actor, $kind, $customerVisible, $original, $customer, $bytes, $timestamp, $id, &$paths): QcEvidence {
                $inspection = app(QcInspectionService::class)->editable($inspection);
                if (! Storage::disk('local')->put($original, $bytes)) {
                    throw new \RuntimeException('Evidence storage failed.');
                }
                $paths[] = $original;
                $limit = trim((string) ini_get('memory_limit'));
                $budget = (float) $limit * match (strtolower(substr($limit, -1))) {
                    'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1
                };
                $dimensions = getimagesizefromstring($bytes);
                if ($budget > 0 && memory_get_usage(true) + $dimensions[0] * $dimensions[1] * 8 + strlen($bytes) * 2 + 20971520 > $budget) {
                    throw ValidationException::withMessages(['file' => 'This image is too large for safe processing. Upload a smaller JPG or PNG.']);
                }
                $image = imagecreatefromstring($bytes);
                if (! $image) {
                    throw ValidationException::withMessages(['file' => 'This image could not be read. Try another JPG or PNG.']);
                }
                // Never upscale narrow photos: watermark padding must not amplify pixel/memory use.
                $photoWidth = min(1600, imagesx($image));
                $width = max(700, $photoWidth);
                $height = max(1, (int) round(imagesy($image) * $photoWidth / imagesx($image)));
                $canvas = imagecreatetruecolor($width, $height + 120);
                imagefill($canvas, 0, 0, imagecolorallocate($canvas, 18, 32, 54));
                imagecopyresampled($canvas, $image, 0, 0, 0, 0, $photoWidth, $height, imagesx($image), imagesy($image));
                $logo = imagecreatefrompng(public_path('branding/tech-point-zone-logo.png'));
                if ($logo) {
                    $logoHeight = min(85, (int) round(imagesy($logo) * 85 / imagesx($logo)));
                    imagecopyresampled($canvas, $logo, 8, $height + 15, 0, 0, 85, $logoHeight, imagesx($logo), imagesy($logo));
                    imagedestroy($logo);
                }
                $white = imagecolorallocate($canvas, 255, 255, 255);
                foreach (['TECH POINT ZONE - VERIFIED QC', 'QC: '.$inspection->device->reference.' v'.$inspection->version, $timestamp->format('d M Y H:i:s T'), self::KINDS[$kind]] as $line => $text) {
                    imagestring($canvas, 4, 105, $height + 12 + $line * 24, $text, $white);
                }
                ob_start();
                imagejpeg($canvas, null, 88);
                $derivative = ob_get_clean();
                imagedestroy($image);
                imagedestroy($canvas);
                if (! Storage::disk('local')->put($customer, $derivative)) {
                    throw new \RuntimeException('Customer evidence storage failed.');
                }
                $paths[] = $customer;
                $evidence = $inspection->evidence()->create(['public_id' => $id, 'kind' => $kind, 'customer_visible' => $customerVisible, 'original_path' => $original, 'customer_path' => $customer, 'checksum' => hash('sha256', $bytes), 'uploaded_by' => $actor->id, 'uploaded_at' => $timestamp]);
                app(ActivityLogger::class)->log('qc.evidence_uploaded', $actor, $inspection, ['evidence_kind' => $kind, 'customer_visible' => $customerVisible]);

                return $evidence;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($paths);
            throw $exception;
        }
    }
}
