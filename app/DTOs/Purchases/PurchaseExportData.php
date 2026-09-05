<?php

namespace App\DTOs\Purchases;

final readonly class PurchaseExportData
{
    public function __construct(
        public ?string $status = null,
        public int|string|null $supplierId = null,
        public ?int $warehouseId = null,
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
    ) {}

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return array_filter([
            'status' => $this->status, 'supplier_id' => $this->supplierId, 'warehouse_id' => $this->warehouseId,
            'date_from' => $this->dateFrom, 'date_to' => $this->dateTo,
        ], fn ($value): bool => $value !== null && $value !== '');
    }
}
