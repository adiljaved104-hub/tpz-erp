<?php

namespace App\Filament\Resources\OpeningStockEntries\Pages;

use App\Actions\Inventory\PostOpeningStock;
use App\DTOs\Inventory\PostOpeningStockData;
use App\Filament\Resources\OpeningStockEntries\OpeningStockEntryResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateOpeningStockEntry extends CreateRecord
{
    protected static string $resource = OpeningStockEntryResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(PostOpeningStock::class)->handle(new PostOpeningStockData(
            productId: (int) $data['product_id'],
            warehouseId: (int) $data['warehouse_id'],
            availableQuantity: (int) $data['available_quantity'],
            damagedQuantity: (int) $data['damaged_quantity'],
            unitCost: (string) $data['unit_cost'],
            reason: $data['reason'],
            idempotencyKey: $data['idempotency_key'],
        ), auth()->user())->source;
    }
}
