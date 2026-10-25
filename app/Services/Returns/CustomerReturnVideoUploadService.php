<?php

namespace App\Services\Returns;

use App\Jobs\FinalizeCustomerReturnVideo;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnEvidence;
use App\Models\CustomerReturnItem;
use App\Models\CustomerReturnVideoUploadSession;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Private, expiring transport for videos; only record() creates permanent evidence. */
class CustomerReturnVideoUploadService
{
    public const CHUNK_BYTES = 5 * 1024 * 1024;

    public const RETENTION_HOURS = 48;

    public function start(CustomerReturn $return, CustomerReturnItem $item, User $actor, array $input): CustomerReturnVideoUploadSession
    {
        abort_unless($item->customer_return_id === $return->id, 404);
        app(CustomerReturnEvidenceAuthorization::class)->authorizeRecord($actor, $return);
        $data = Validator::make($input, [
            'upload_id' => ['required', 'uuid'], 'request_id' => ['required', 'uuid'],
            'original_name' => ['required', 'string', 'max:255'],
            'mime_type' => ['required', 'in:video/mp4,video/quicktime'],
            'byte_size' => ['required', 'integer', 'min:1', 'max:'.(int) config('customer_return_evidence.max_video_kib') * 1024],
            'source_md5' => ['required', 'regex:/^[a-f0-9]{32}$/'],
            'duration_seconds' => ['required', 'integer', 'min:1', 'max:'.(int) config('customer_return_evidence.max_video_seconds')],
            'problem_type' => ['required', 'in:'.implode(',', CustomerReturnEvidenceService::PROBLEMS)],
            'actual_product_name' => ['nullable', 'string', 'max:255'],
            'actual_serial_number' => ['nullable', 'string', 'max:255'],
            'physical_condition' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:5000'],
            'captured_at_device' => ['required', 'date', 'regex:/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?(?:Z|[+-]\d\d:\d\d)$/'],
            'capture_timezone' => ['required', 'timezone'],
        ])->validate();
        $finding = collect($data)->only(['problem_type', 'actual_product_name', 'actual_serial_number', 'physical_condition', 'remarks'])->all();
        $capture = collect($data)->only(['captured_at_device', 'capture_timezone'])->all();
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', basename(str_replace('\\', '/', $data['original_name'])));
        if ($name === null || in_array($name, ['', '.', '..'], true)) {
            throw ValidationException::withMessages(['original_name' => 'Choose a valid video filename.']);
        }
        if (! CustomerReturnVideoUploadSession::query()->where('upload_id', $data['upload_id'])->exists()
            && CustomerReturnVideoUploadSession::query()->where('employee_user_id', $actor->id)
                ->where('status', 'open')->where('expires_at', '>', now())->count() >= 4) {
            throw ValidationException::withMessages(['upload_id' => 'Finish or wait for an existing video upload before starting another.']);
        }
        $session = CustomerReturnVideoUploadSession::query()->createOrFirst(['upload_id' => $data['upload_id']], [
            'id' => (string) Str::uuid(), 'request_id' => $data['request_id'],
            'customer_return_id' => $return->id, 'customer_return_item_id' => $item->id,
            'employee_user_id' => $actor->id, 'original_name' => $name,
            'mime_type' => $data['mime_type'], 'byte_size' => $data['byte_size'], 'duration_seconds' => $data['duration_seconds'],
            'source_md5' => $data['source_md5'],
            'finding' => $finding, 'capture' => $capture, 'chunks' => [],
            'expires_at' => now()->addHours(self::RETENTION_HOURS),
        ]);
        if (! $session->wasRecentlyCreated) {
            abort_unless($session->employee_user_id === $actor->id, 404);
            if ($session->customer_return_id !== $return->id || $session->customer_return_item_id !== $item->id
                || $session->request_id !== $data['request_id'] || $session->byte_size !== (int) $data['byte_size']
                || $session->duration_seconds !== (int) $data['duration_seconds'] || $session->mime_type !== $data['mime_type']
                || $session->source_md5 !== $data['source_md5']
                || $session->original_name !== $name
                || $session->finding != $finding || $session->capture != $capture) {
                throw ValidationException::withMessages(['upload_id' => 'This upload ID belongs to a different recording.']);
            }
        }

        return $this->authorized($session, $actor);
    }

    public function status(CustomerReturnVideoUploadSession $session, User $actor): array
    {
        $session = $session->fresh();
        abort_unless($session, 404);
        $session = $this->authorized($session, $actor);

        return [
            'session_id' => $session->id, 'upload_id' => $session->upload_id,
            'status' => $session->status, 'chunk_bytes' => self::CHUNK_BYTES,
            'total_chunks' => $this->totalChunks($session),
            'received_chunks' => array_map('intval', array_keys($session->chunks ?? [])),
            'expires_at' => $session->expires_at->toIso8601String(),
            'evidence_id' => $session->evidence_id,
        ];
    }

    public function chunk(CustomerReturnVideoUploadSession $session, User $actor, int $index, string $checksum, UploadedFile $file): array
    {
        $this->authorized($session, $actor);
        $lock = Cache::lock($this->lockName($session), 120);

        return $lock->block(10, function () use ($session, $actor, $index, $checksum, $file): array {
            return DB::transaction(function () use ($session, $actor, $index, $checksum, $file): array {
                $current = CustomerReturnVideoUploadSession::query()->lockForUpdate()->findOrFail($session->id);
                $this->authorized($current, $actor);
                abort_unless($current->status === 'open', 409);
                if ($index < 0 || $index >= $this->totalChunks($current)
                    || ! preg_match('/^[a-f0-9]{32}$/', $checksum)) {
                    throw ValidationException::withMessages(['chunk' => 'Invalid chunk number or checksum.']);
                }
                $expectedBytes = min(self::CHUNK_BYTES, $current->byte_size - $index * self::CHUNK_BYTES);
                if ($file->getSize() !== $expectedBytes || $file->getSize() > self::CHUNK_BYTES
                    || ! hash_equals($checksum, md5_file($file->getRealPath()))) {
                    throw ValidationException::withMessages(['chunk' => 'The chunk size or checksum did not match. Retry this part.']);
                }
                $chunks = $current->chunks ?? [];
                $path = $this->directory($current).'/'.$index.'.part';
                if (isset($chunks[$index])) {
                    if ($chunks[$index]['md5'] !== $checksum) {
                        throw ValidationException::withMessages(['chunk' => 'This part was already saved with different content.']);
                    }
                    if (Storage::disk('local')->exists($path)
                        && hash_equals($chunks[$index]['sha256'], hash_file('sha256', Storage::disk('local')->path($path)))) {
                        return $this->status($current, $actor);
                    }
                    // Repair a missing or damaged temporary part with the same
                    // authenticated file content; never change its accepted checksum.
                }
                $stream = fopen($file->getRealPath(), 'rb');
                if ($stream === false) {
                    throw new \RuntimeException('The upload part could not be read.');
                }
                try {
                    if (! Storage::disk('local')->put($path, $stream)) {
                        throw new \RuntimeException('The upload part could not be stored.');
                    }
                } finally {
                    fclose($stream);
                }
                $chunks[$index] = ['md5' => $checksum, 'sha256' => hash_file('sha256', $file->getRealPath()), 'bytes' => $expectedBytes];
                $current->chunks = $chunks;
                $current->save();

                return $this->status($current, $actor);
            });
        });
    }

    public function requestFinalization(CustomerReturnVideoUploadSession $session, User $actor, bool $supportsAsync): array
    {
        $this->authorized($session, $actor);
        abort_unless($supportsAsync, 426, 'Update the mobile app to finish video uploads safely. Your recording is still pending.');

        return Cache::lock($this->lockName($session), 120)->block(10, function () use ($session, $actor): array {
            $current = $this->authorized($session->fresh() ?? abort(404), $actor);
            if ($current->status === 'completed' || $current->status === 'processing') {
                return $this->status($current, $actor);
            }
            abort_unless($current->status === 'open', 409, 'This video upload cannot be finalized.');
            if (count($current->chunks ?? []) !== $this->totalChunks($current)) {
                throw ValidationException::withMessages(['chunks' => 'Some upload parts are still missing.']);
            }
            $current->status = 'processing';
            $current->save();
            try {
                FinalizeCustomerReturnVideo::dispatch($current->id)->onConnection('return_evidence_uploads');
            } catch (Throwable $exception) {
                $current->status = 'open';
                $current->save();
                throw $exception;
            }

            return $this->status($current, $actor);
        });
    }

    /** Worker-only: never run 200 MiB assembly inside an HTTP request. */
    public function finalize(CustomerReturnVideoUploadSession $session, User $actor): ?CustomerReturnEvidence
    {
        $this->authorized($session, $actor);

        return Cache::lock($this->lockName($session), 660)->block(30, function () use ($session, $actor): ?CustomerReturnEvidence {
            $current = $session->fresh();
            abort_unless($current, 404);
            $current = $this->authorized($current, $actor);
            if ($current->evidence_id) {
                return CustomerReturnEvidence::query()->findOrFail($current->evidence_id);
            }
            if ($current->status !== 'processing') {
                return null;
            }
            if (count($current->chunks ?? []) !== $this->totalChunks($current)) {
                throw ValidationException::withMessages(['chunks' => 'Some upload parts are still missing.']);
            }
            $directory = $this->directory($current);
            $disk = Storage::disk('local');
            $disk->makeDirectory($directory);
            $assembled = $disk->path($directory.'/assembled.tmp');
            $output = fopen($assembled, 'wb');
            if ($output === false) {
                throw new \RuntimeException('The video could not be assembled.');
            }
            try {
                for ($index = 0; $index < $this->totalChunks($current); $index++) {
                    $meta = $current->chunks[$index] ?? null;
                    $path = $directory.'/'.$index.'.part';
                    if (! $meta || ! $disk->exists($path)
                        || $disk->size($path) !== $meta['bytes']
                        || ! hash_equals($meta['sha256'], hash_file('sha256', $disk->path($path)))) {
                        throw ValidationException::withMessages(['chunks' => 'An upload part failed integrity verification. Retry the recording upload.']);
                    }
                    $input = fopen($disk->path($path), 'rb');
                    if ($input === false) {
                        throw new \RuntimeException('An upload part could not be read.');
                    }
                    try {
                        if (stream_copy_to_stream($input, $output) !== $meta['bytes']) {
                            throw new \RuntimeException('An upload part could not be assembled.');
                        }
                    } finally {
                        fclose($input);
                    }
                }
            } finally {
                fclose($output);
            }
            try {
                if (filesize($assembled) !== $current->byte_size
                    || ! hash_equals($current->source_md5, md5_file($assembled))) {
                    throw ValidationException::withMessages(['file' => 'The assembled video did not match the original recording.']);
                }
                $item = CustomerReturnItem::query()->findOrFail($current->customer_return_item_id);
                $file = new UploadedFile($assembled, $current->original_name, $current->mime_type, null, true);
                $evidence = app(CustomerReturnEvidenceService::class)->record($item, $actor, [
                    ...$current->finding, ...$current->capture,
                    'request_id' => $current->request_id, 'upload_id' => $current->upload_id,
                    'duration_seconds' => $current->duration_seconds,
                ], $file);
                $current->status = 'completed';
                $current->evidence_id = $evidence->id;
                $current->save();
                $disk->deleteDirectory($directory);

                return $evidence;
            } finally {
                if (is_file($assembled)) {
                    unlink($assembled);
                }
            }
        });
    }

    public function pruneExpired(): int
    {
        if (! Schema::hasTable('customer_return_video_upload_sessions')) {
            return 0; // A scheduler tick during forward deployment must be harmless.
        }
        $count = 0;
        CustomerReturnVideoUploadSession::query()->where('expires_at', '<', now())->orderBy('expires_at')
            ->chunkById(100, function ($sessions) use (&$count): void {
                foreach ($sessions as $session) {
                    $lock = Cache::lock($this->lockName($session), 10);
                    if (! $lock->get()) {
                        continue;
                    }
                    try {
                        if ($session->fresh()?->expires_at?->isPast()) {
                            $disk = Storage::disk('local');
                            $directory = $this->directory($session);
                            if ($disk->directoryExists($directory) && ! $disk->deleteDirectory($directory)) {
                                continue; // Retain the DB row so cleanup can retry safely.
                            }
                            $session->delete();
                            $count++;
                        }
                    } finally {
                        $lock->release();
                    }
                }
            }, 'id');

        return $count;
    }

    public function failFinalization(CustomerReturnVideoUploadSession $session): void
    {
        DB::transaction(function () use ($session): void {
            $current = CustomerReturnVideoUploadSession::query()->lockForUpdate()->find($session->id);
            if (! $current || $current->status !== 'processing') {
                return;
            }
            // A worker may have saved immutable evidence before losing its DB
            // acknowledgement. Recover that success rather than invite a retry.
            $existing = CustomerReturnEvidence::query()->where('upload_id', $current->upload_id)->first();
            $current->evidence_id = $existing?->id;
            $current->status = $existing ? 'completed' : 'failed';
            $current->save();
        });
    }

    private function authorized(CustomerReturnVideoUploadSession $session, User $actor): CustomerReturnVideoUploadSession
    {
        abort_unless($session->employee_user_id === $actor->id, 404);
        $return = CustomerReturn::query()->findOrFail($session->customer_return_id);
        app(CustomerReturnEvidenceAuthorization::class)->authorizeRecord($actor, $return);
        abort_if($session->expires_at->isPast() && $session->status !== 'completed', 410, 'This upload expired. Start it again.');

        return $session;
    }

    private function totalChunks(CustomerReturnVideoUploadSession $session): int
    {
        return (int) ceil($session->byte_size / self::CHUNK_BYTES);
    }

    private function directory(CustomerReturnVideoUploadSession $session): string
    {
        return 'customer-return-evidence/upload-sessions/'.$session->id;
    }

    private function lockName(CustomerReturnVideoUploadSession $session): string
    {
        return 'return-video-upload:'.$session->id;
    }
}
