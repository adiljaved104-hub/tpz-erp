<?php

namespace App\Filament\Resources\StockTransfers\Pages;

use App\Actions\StockTransfers\CreateStockTransfer as CreateStockTransferAction;
use App\DTOs\StockTransfers\CreateStockTransferData;
use App\DTOs\StockTransfers\StockTransferItemData;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateStockTransfer extends CreateRecord
{
    protected static string $resource = StockTransferResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateStockTransferAction::class)->handle(new CreateStockTransferData(
            sourceWarehouseId: (int) $data['source_warehouse_id'], destinationWarehouseId: (int) $data['destination_warehouse_id'], transferDate: (string) $data['transfer_date'],
            items: collect($data['items'])->map(fn (array $item): StockTransferItemData => new StockTransferItemData((int) $item['product_id'], (int) $item['quantity']))->all(),
            idempotencyKey: (string) str()->uuid(), handledByEmployeeId: isset($data['handled_by_employee_id']) ? (int) $data['handled_by_employee_id'] : null, notes: $data['notes'] ?? null,
        ), auth()->user());
    }
}
