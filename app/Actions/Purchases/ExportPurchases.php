<?php

namespace App\Actions\Purchases;

use App\DTOs\Purchases\PurchaseExportData;
use App\Models\User;
use App\Services\Purchases\PurchaseExportService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportPurchases
{
    public function __construct(private readonly PurchaseExportService $exports) {}

    public function handle(PurchaseExportData $data, User $actor): StreamedResponse
    {
        return $this->exports->stream($data, $actor);
    }
}
