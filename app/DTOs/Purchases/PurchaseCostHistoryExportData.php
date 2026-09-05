<?php

namespace App\DTOs\Purchases;

final readonly class PurchaseCostHistoryExportData
{
    public function __construct(public PurchaseCostHistoryFilterData $filters) {}
}
