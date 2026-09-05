<?php

namespace App\Filament\Resources\Warehouses\Pages;

use App\Actions\Warehouses\UpdateWarehouse;
use App\DTOs\Warehouses\UpdateWarehouseData;
use App\Enums\InventoryLocationType;
use App\Filament\Resources\Warehouses\WarehouseResource;
use App\Models\Warehouse;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditWarehouse extends EditRecord
{
    protected static string $resource = WarehouseResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Warehouse $record */
        return app(UpdateWarehouse::class)->handle($record, new UpdateWarehouseData(
            name: $data['name'],
            code: $data['code'],
            address: $data['address'] ?? null,
            locationType: $data['location_type'] instanceof InventoryLocationType ? $data['location_type'] : InventoryLocationType::from($data['location_type']),
            marketplacePlatformId: isset($data['marketplace_platform_id']) ? (int) $data['marketplace_platform_id'] : null,
            fulfillmentTag: $data['fulfillment_tag'] ?? null,
        ), auth()->user());
    }
}
