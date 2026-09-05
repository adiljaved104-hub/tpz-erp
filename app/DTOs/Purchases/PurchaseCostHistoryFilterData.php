<?php

namespace App\DTOs\Purchases;

final readonly class PurchaseCostHistoryFilterData
{
    public function __construct(
        public ?int $productId = null,
        public int|string|null $supplierId = null,
        public ?int $warehouseId = null,
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
    ) {}

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return array_filter([
            'product_id' => $this->productId, 'supplier_id' => $this->supplierId, 'warehouse_id' => $this->warehouseId,
            'date_from' => $this->dateFrom, 'date_to' => $this->dateTo,
        ], fn ($value): bool => $value !== null && $value !== '');
    }
}
