<?php

namespace App\DTOs\Returns;

final readonly class CreateCustomerReturnData
{
    /** @param array<int, array{order_fulfillment_item_id:int,quantity:int,return_reason:string,reason_notes?:?string}> $items */
    public function __construct(
        public int $orderId,
        public ?int $receivingWarehouseId,
        public array $items,
        public string $idempotencyKey,
        public ?string $notes = null,
    ) {}
}
