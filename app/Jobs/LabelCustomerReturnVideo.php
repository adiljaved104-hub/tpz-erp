<?php

namespace App\Jobs;

use App\Models\CustomerReturnEvidence;
use App\Services\Returns\CustomerReturnEvidenceLabelService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class LabelCustomerReturnVideo implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 330;

    public int $uniqueFor = 3600;

    public function backoff(): array
    {
        return [60, 300];
    }

    public function __construct(public readonly int $evidenceId) {}

    public function uniqueId(): string
    {
        return (string) $this->evidenceId;
    }

    public function handle(CustomerReturnEvidenceLabelService $labels): void
    {
        $evidence = CustomerReturnEvidence::query()->find($this->evidenceId);
        if ($evidence && $evidence->media_type === 'video') {
            $labels->createIfSupported($evidence, true);
        }
    }
}
