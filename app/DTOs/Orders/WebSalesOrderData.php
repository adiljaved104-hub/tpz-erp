<?php

namespace App\DTOs\Orders;

use App\Enums\WebSalesChannel;
use App\Enums\WebSalesDeliveryType;

final readonly class WebSalesOrderData
{
    /** @param array<int, OrderItemData> $items */
    public function __construct(
        public string $customerName,
        public string $customerPhone,
        public WebSalesChannel $channel,
        public WebSalesDeliveryType $deliveryType,
        public ?string $courierName,
        public ?string $trackingNumber,
        public array $items,
        public string $idempotencyKey,
        public ?string $notes = null,
    ) {}
}
