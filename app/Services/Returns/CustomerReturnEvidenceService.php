<?php

namespace App\Services\Returns;

use App\Jobs\LabelCustomerReturnPhoto;
use App\Jobs\LabelCustomerReturnVideo;
use App\Models\CustomerReturnEvidence;
use App\Models\CustomerReturnFinding;
use App\Models\CustomerReturnItem;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CustomerReturnEvidenceService
{
    public const PROBLEMS = [
        'sellable', 'damaged', 'wrong_item', 'serial_mismatch', 'defective',
        'missing_accessories', 'empty_package', 'other',
    ];

    public function record(CustomerReturnItem $item, User $actor, array $input, UploadedFile $file): CustomerReturnEvidence
    {
        $return = $item->customerReturn;
        app(CustomerReturnEvidenceAuthorization::class)->authorizeRecord($actor, $return);
        $validated = Validator::make([...$input, 'file' => $file], [
            'request_id' => ['required', 'uuid'], 'upload_id' => ['required', 'uuid'],
            'problem_type' => ['required', 'in:'.implode(',', self::PROBLEMS)],
            'actual_product_name' => ['nullable', 'string', 'max:255'],
            'actual_serial_number' => ['nullable', 'string', 'max:255'],
            'physical_condition' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:5000'],
            'duration_seconds' => ['nullable', 'integer', 'min:1', 'max:'.config('customer_return_evidence.max_video_seconds')],
            'captured_at_device' => ['nullable', 'required_with:capture_timezone', 'date',
                'regex:/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?(?:Z|[+-]\d\d:\d\d)$/'],
            'capture_timezone' => ['nullable', 'required_with:captured_at_device', 'timezone'],
            'video_rotation_degrees' => ['nullable', 'integer', 'in:0,90,180,270'],
            'device_recording_orientation' => ['nullable', 'in:portrait_up,portrait_down,landscape_left,landscape_right'],
            'file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/quicktime',
                'max:'.max(config('customer_return_evidence.max_photo_kib'), config('customer_return_evidence.max_video_kib'))],
        ])->validate();

        $mime = $file->getMimeType();
        $video = in_array($mime, ['video/mp4', 'video/quicktime'], true);
        $limit = (int) config($video ? 'customer_return_evidence.max_video_kib' : 'customer_return_evidence.max_photo_kib');
        if ($file->getSize() > $limit * 1024) {
            throw ValidationException::withMessages(['file' => 'This file exceeds the allowed '.($video ? 'video' : 'photo').' size.']);
        }
        if ($video && empty($validated['duration_seconds'])) {
            throw ValidationException::withMessages(['duration_seconds' => 'Video duration is required.']);
        }
        if (! $video && ! empty($validated['duration_seconds'])) {
            throw ValidationException::withMessages(['duration_seconds' => 'Photo uploads cannot have video duration.']);
        }
        if (! $video && isset($validated['device_recording_orientation'])) {
            throw ValidationException::withMessages(['device_recording_orientation' => 'Only videos may report device orientation.']);
        }
        if (! $video && isset($validated['video_rotation_degrees'])) {
            throw ValidationException::withMessages(['video_rotation_degrees' => 'Only videos may have a display rotation correction.']);
        }
        if ($video) {
            $actualDuration = app(CustomerReturnVideoDuration::class)->seconds($file->getRealPath());
            if ($actualDuration > (int) config('customer_return_evidence.max_video_seconds')
                || abs($actualDuration - (int) $validated['duration_seconds']) > 5) {
                throw ValidationException::withMessages(['duration_seconds' => 'The video exceeds the allowed duration or its duration does not match the upload.']);
            }
        }
        $checksum = hash_file('sha256', $file->getRealPath());
        $capturedAt = isset($validated['captured_at_device']) ? Carbon::parse($validated['captured_at_device'])->utc() : null;
        if ($capturedAt?->isAfter(now()->addMinutes(5))) {
            throw ValidationException::withMessages(['captured_at_device' => 'The device capture time is in the future. Check the phone clock.']);
        }
        $storedPath = null;
        try {
            $evidence = DB::transaction(function () use ($item, $actor, $validated, $file, $mime, $video, $checksum, $capturedAt, &$storedPath): CustomerReturnEvidence {
                $locked = CustomerReturnItem::query()->lockForUpdate()->findOrFail($item->id);
                app(CustomerReturnEvidenceAuthorization::class)->authorizeRecord($actor, $locked->customerReturn);
                $existing = CustomerReturnEvidence::query()->where('upload_id', $validated['upload_id'])->first();
                if ($existing) {
                    if ($existing->uploaded_by_user_id !== $actor->id || $existing->sha256 !== $checksum
                        || $existing->finding->customer_return_item_id !== $locked->id
                        || $existing->finding->request_id !== $validated['request_id']
                        || ($video && (int) ($existing->video_rotation_degrees ?? 0) !== (int) ($validated['video_rotation_degrees'] ?? 0))
                        || ($video && $existing->device_recording_orientation !== ($validated['device_recording_orientation'] ?? null))) {
                        throw ValidationException::withMessages(['upload_id' => 'This upload ID belongs to different evidence.']);
                    }

                    return $existing;
                }
                $finding = CustomerReturnFinding::query()->where('request_id', $validated['request_id'])->first();
                if ($finding) {
                    if ($finding->customer_return_item_id !== $locked->id || $finding->recorded_by_user_id !== $actor->id
                        || $finding->problem_type !== $validated['problem_type']
                        || $finding->actual_product_name !== ($validated['actual_product_name'] ?? null)
                        || $finding->actual_serial_number !== ($validated['actual_serial_number'] ?? null)
                        || $finding->physical_condition !== ($validated['physical_condition'] ?? null)
                        || $finding->remarks !== ($validated['remarks'] ?? null)) {
                        throw ValidationException::withMessages(['request_id' => 'This finding ID belongs to a different report.']);
                    }
                } else {
                    $finding = CustomerReturnFinding::query()->create([
                        'customer_return_item_id' => $locked->id, 'recorded_by_user_id' => $actor->id,
                        'request_id' => $validated['request_id'], 'problem_type' => $validated['problem_type'],
                        'actual_product_name' => $validated['actual_product_name'] ?? null,
                        'actual_serial_number' => $validated['actual_serial_number'] ?? null,
                        'physical_condition' => $validated['physical_condition'] ?? null,
                        'remarks' => $validated['remarks'] ?? null, 'recorded_at' => now(),
                    ]);
                }
                $extension = match ($mime) {
                    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
                    'video/mp4' => 'mp4', default => 'mov',
                };
                // A fresh storage name prevents a concurrent duplicate upload ID on
                // another item from overwriting the first item's original file.
                $path = Storage::disk('local')->putFileAs('customer-return-evidence/originals', $file, Str::uuid().'.'.$extension);
                if (! $path || ! Storage::disk('local')->exists($path)) {
                    throw new \RuntimeException('Return evidence could not be stored.');
                }
                $storedPath = $path;
                $evidence = CustomerReturnEvidence::query()->create([
                    'finding_id' => $finding->id, 'uploaded_by_user_id' => $actor->id,
                    'upload_id' => $validated['upload_id'], 'media_type' => $video ? 'video' : 'photo',
                    'mime_type' => $mime, 'original_path' => $path,
                    'original_name' => basename($file->getClientOriginalName()),
                    'byte_size' => $file->getSize(), 'sha256' => $checksum,
                    'duration_seconds' => $video ? (int) $validated['duration_seconds'] : null,
                    'video_rotation_degrees' => $video ? ($validated['video_rotation_degrees'] ?? null) : null,
                    'device_recording_orientation' => $video ? ($validated['device_recording_orientation'] ?? null) : null,
                    'captured_at_device' => $capturedAt,
                    'capture_timezone' => $validated['capture_timezone'] ?? null,
                    'uploaded_at' => now(),
                ]);
                app(CustomerReturnExceptionService::class)->record($evidence, $locked, $actor);
                app(ActivityLogger::class)->log('customer_return.evidence_uploaded', $actor, $locked->customerReturn, [
                    'return_item_id' => $locked->id, 'evidence_id' => $evidence->id, 'media_type' => $evidence->media_type,
                    'qc_review_required' => $locked->qcEvidenceReviewReason() !== null,
                ]);

                return $evidence;
            }, 3);
        } catch (Throwable $e) {
            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $e;
        }
        // The original has committed. Derivative failure must never delete it.
        if ($evidence->media_type === 'photo') {
            try {
                if ($evidence->captured_at_device !== null && config('queue.default') !== 'sync') {
                    LabelCustomerReturnPhoto::dispatch($evidence->id)
                        ->onConnection('return_evidence_labels')->afterCommit();
                } else {
                    app(CustomerReturnEvidenceLabelService::class)->createIfSupported($evidence);
                }
            } catch (Throwable $exception) {
                Log::warning('Return photo label is pending.', [
                    'evidence_id' => $evidence->id, 'exception' => $exception::class,
                ]);
            }
        } elseif ($evidence->captured_at_device !== null
            && config('customer_return_evidence.ffmpeg_binary')
            && config('queue.default') !== 'sync') {
            try {
                if (! $evidence->label()->exists()) {
                    LabelCustomerReturnVideo::dispatch($evidence->id)
                        ->onConnection('return_evidence_labels')->afterCommit();
                }
            } catch (Throwable $exception) {
                // The original evidence committed successfully. Queue failure
                // must not turn an acknowledged upload into a deceptive 500.
                Log::warning('Return video label is pending.', [
                    'evidence_id' => $evidence->id, 'exception' => $exception::class,
                ]);
            }
        }

        return $evidence;
    }
}
