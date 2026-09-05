<?php

namespace App\Actions\Purchases;

use App\DTOs\Purchases\PurchaseCostHistoryExportData;
use App\Models\User;
use App\Services\Purchases\PurchaseCostHistoryExportService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportPurchaseCostHistory
{
    public function __construct(private readonly PurchaseCostHistoryExportService $exports) {}

    public function handle(PurchaseCostHistoryExportData $data, User $actor): StreamedResponse
    {
        return $this->exports->stream($data->filters, $actor);
    }
}
