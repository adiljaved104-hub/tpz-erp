<?php

namespace App\DTOs\Inventory;

class InventoryAdjustmentData
{
    public function __construct(
        public readonly int $inventoryId,
        public readonly string $type,
        public readonly int $quantity,
        public readonly string $reason,
        public readonly string $idempotencyKey,
        public readonly ?int $receiptItemId = null,
        public readonly ?int $allocationAccountId = null,
        public readonly ?bool $isNewPurchase = null,
        public readonly ?string $valuationUnitCost = null,
    ) {}
}
