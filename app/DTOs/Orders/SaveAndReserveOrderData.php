<?php

namespace App\DTOs\Orders;

final readonly class SaveAndReserveOrderData
{
    /** @param array<int, OrderItemData> $items */
    public function __construct(
        public int $warehouseId,
        public ?int $platformId,
        public ?string $externalOrderNumber,
        public string $orderDate,
        public ?int $handledByEmployeeId,
        public ?string $notes,
        public array $items,
        public string $idempotencyKey,
        public ?string $webSalesChannel = null,
        public ?string $customerName = null,
        public ?string $customerPhone = null,
        public ?string $deliveryType = null,
        public ?string $courierName = null,
        public ?string $trackingNumber = null,
    ) {}
}
