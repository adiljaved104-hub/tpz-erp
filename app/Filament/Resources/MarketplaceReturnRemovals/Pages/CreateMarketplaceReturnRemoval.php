<?php

namespace App\Filament\Resources\MarketplaceReturnRemovals\Pages;

use App\Actions\Returns\CreateMarketplaceReturnRemoval as CreateAction;
use App\DTOs\Returns\CreateMarketplaceReturnRemovalData;
use App\DTOs\Returns\MarketplaceRemovalItemData;
use App\Enums\MarketplaceRemovalSourceStockType;
use App\Exceptions\CustomerReturnException;
use App\Filament\Resources\MarketplaceReturnRemovals\MarketplaceReturnRemovalResource;
use App\Models\MarketplaceReturnRemoval;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;

class CreateMarketplaceReturnRemoval extends CreateRecord
{
    protected static string $resource = MarketplaceReturnRemovalResource::class;

    protected function handleRecordCreation(array $data): MarketplaceReturnRemoval
    {
        try {
            return app(CreateAction::class)->handle(new CreateMarketplaceReturnRemovalData(
                (int) $data['marketplace_platform_id'],
                (int) $data['source_warehouse_id'],
                (int) $data['destination_warehouse_id'],
                array_map(fn (array $item): MarketplaceRemovalItemData => new MarketplaceRemovalItemData(
                    (int) $item['product_id'],
                    MarketplaceRemovalSourceStockType::from($item['source_stock_type']),
                    (int) $item['quantity'],
                    isset($item['customer_return_item_id']) ? (int) $item['customer_return_item_id'] : null,
                ), $data['items']),
                (string) str()->uuid(),
                $data['external_removal_reference'] ?? null,
                $data['notes'] ?? null,
            ), auth()->user());
        } catch (CustomerReturnException $exception) {
            Notification::make()->danger()->title('Cannot request Marketplace Removal')->body($exception->getMessage())->send();
            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
