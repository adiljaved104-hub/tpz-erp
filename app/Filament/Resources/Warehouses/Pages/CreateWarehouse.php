<?php

namespace App\Filament\Resources\Warehouses\Pages;

use App\Actions\Warehouses\CreateWarehouse as CreateWarehouseAction;
use App\DTOs\Warehouses\CreateWarehouseData;
use App\Enums\InventoryLocationType;
use App\Filament\Resources\Warehouses\WarehouseResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateWarehouse extends CreateRecord
{
    protected static string $resource = WarehouseResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateWarehouseAction::class)->handle(new CreateWarehouseData(
            name: $data['name'],
            code: $data['code'],
            address: $data['address'] ?? null,
            locationType: $data['location_type'] instanceof InventoryLocationType ? $data['location_type'] : InventoryLocationType::from($data['location_type']),
            marketplacePlatformId: isset($data['marketplace_platform_id']) ? (int) $data['marketplace_platform_id'] : null,
            fulfillmentTag: $data['fulfillment_tag'] ?? null,
        ), auth()->user());
    }
}
