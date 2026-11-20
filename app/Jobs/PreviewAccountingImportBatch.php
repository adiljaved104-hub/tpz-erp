<?php

namespace App\Jobs;

use App\Models\AccountingImportBatch;
use App\Services\Accounting\QuickBooksImportPreviewService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PreviewAccountingImportBatch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 1800;

    public function __construct(public readonly int $batchId) {}

    public function handle(QuickBooksImportPreviewService $preview): void
    {
        $batch = AccountingImportBatch::query()->with('creator')->findOrFail($this->batchId);
        $preview->preview($batch, $batch->creator);
    }

    public function failed(Throwable $exception): void
    {
        AccountingImportBatch::query()->whereKey($this->batchId)->where('status', 'previewing')->update([
            'status' => 'failed',
            'error_summary' => json_encode(['preview' => mb_substr($exception->getMessage(), 0, 1000)]),
            'updated_at' => now(),
        ]);
    }
}
