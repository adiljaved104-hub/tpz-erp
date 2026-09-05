<?php

namespace App\DTOs\Warehouses;

use App\Enums\InventoryLocationType;

final readonly class UpdateWarehouseData
{
    public function __construct(
        public string $name,
        public string $code,
        public ?string $address = null,
        public InventoryLocationType $locationType = InventoryLocationType::CompanyWarehouse,
        public ?int $marketplacePlatformId = null,
        public ?string $fulfillmentTag = null,
    ) {}
}
