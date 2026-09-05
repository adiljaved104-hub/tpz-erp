<?php

namespace App\Filament\Resources\ProductInventories\Schemas;

use App\Enums\InventoryPermission;
use App\Models\ProductInventory;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Inventory\WeightedAverageCostCalculator;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductInventoryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Inventory Quantities')
                ->description('Available includes Reserved. Sellable is Available minus Reserved.')
                ->schema([
                    TextEntry::make('product.name')->label('Product'),
                    TextEntry::make('product.sku')->label('SKU'),
                    TextEntry::make('warehouse.name')->label('Warehouse'),
                    TextEntry::make('available_quantity')->label('Available'),
                    TextEntry::make('reserved_quantity')->label('Reserved'),
                    TextEntry::make('sellable_quantity')->label('Sellable')->state(fn (ProductInventory $record): int => $record->sellableQuantity()),
                    TextEntry::make('damaged_quantity')->label('Damaged'),
                    TextEntry::make('total_on_hand')->label('Total on Hand')->state(fn (ProductInventory $record): int => $record->totalOnHand()),
                    ...(self::canViewFinancials() ? [
                        TextEntry::make('average_cost')->label('Average Cost')->money('AED', decimalPlaces: 2)->placeholder('No cost history'),
                        TextEntry::make('inventory_value')
                            ->label('Inventory Value')
                            ->state(fn (ProductInventory $record): ?string => $record->average_cost === null
                                ? null
                                : app(WeightedAverageCostCalculator::class)->inventoryValue($record->totalOnHand(), $record->average_cost))
                            ->money('AED'),
                    ] : []),
                ])->columns(4),
        ]);
    }

    private static function canViewFinancials(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(InventoryAuthorization::class)->allows($user, InventoryPermission::ViewFinancials);
    }
}
