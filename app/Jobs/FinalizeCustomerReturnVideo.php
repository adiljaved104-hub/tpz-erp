<?php

namespace App\Jobs;

use App\Models\CustomerReturnVideoUploadSession;
use App\Models\User;
use App\Services\Returns\CustomerReturnVideoUploadService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Validation\ValidationException;
use Throwable;

class FinalizeCustomerReturnVideo implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public int $uniqueFor = 3600;

    public function __construct(public readonly string $sessionId) {}

    public function uniqueId(): string
    {
        return $this->sessionId;
    }

    public function backoff(): array
    {
        // A killed worker's cache lock lives at most 660 seconds.
        return [700, 700];
    }

    public function handle(CustomerReturnVideoUploadService $uploads): void
    {
        $session = CustomerReturnVideoUploadSession::query()->find($this->sessionId);
        if (! $session || $session->status !== 'processing') {
            return;
        }
        $actor = User::query()->findOrFail($session->employee_user_id);
        try {
            $uploads->finalize($session, $actor);
        } catch (ValidationException $exception) {
            // A corrupt or over-duration source cannot improve on retry.
            $uploads->failFinalization($session);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $session = CustomerReturnVideoUploadSession::query()->find($this->sessionId);
        if ($session) {
            app(CustomerReturnVideoUploadService::class)->failFinalization($session);
        }
    }
}
