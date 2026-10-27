<?php

namespace App\Jobs;

use App\Models\CustomerReturnEvidence;
use App\Services\Returns\CustomerReturnEvidenceLabelService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class LabelCustomerReturnPhoto implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function backoff(): array
    {
        return [30, 120];
    }

    public function __construct(public readonly int $evidenceId) {}

    public function uniqueId(): string
    {
        return (string) $this->evidenceId;
    }

    public function handle(CustomerReturnEvidenceLabelService $labels): void
    {
        $evidence = CustomerReturnEvidence::query()->find($this->evidenceId);
        if ($evidence && $evidence->media_type === 'photo') {
            $labels->createIfSupported($evidence, true);
        }
    }
}
