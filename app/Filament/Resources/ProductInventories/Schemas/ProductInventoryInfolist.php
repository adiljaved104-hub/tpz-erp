<?php

namespace App\Filament\Resources\ProductInventories\Schemas;

use App\Enums\InventoryPermission;
use App\Models\ProductInventory;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Inventory\EmployeeOwnedInventoryReadService;
use App\Services\Inventory\InventoryAllocationBreakdownReadService;
use App\Services\Inventory\WeightedAverageCostCalculator;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductInventoryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $owned = app(EmployeeOwnedInventoryReadService::class)->isEmployeeView(auth()->user());

        return $schema->components([
            Section::make('Inventory Quantities')
                ->description($owned
                    ? 'Warehouse-assigned rows show physical Total Stock and ledger Allocated. Other rows show only your own allocation. Reserved is included in Total Stock / My Held, and Sellable is the corresponding total minus Reserved.'
                    : 'Total Stock includes Reserved. Allocated is the sum of recorded ledger balances. Reserved is a subset and is not added again. Sellable is Total Stock minus Reserved.')
                ->schema([
                    TextEntry::make('product.name')->label('Product'),
                    TextEntry::make('product.sku')->label('SKU'),
                    TextEntry::make('warehouse.name')->label('Warehouse'),
                    TextEntry::make('available_quantity')->label($owned ? 'Total Stock / My Held' : 'Total Stock'),
                    TextEntry::make('allocated_quantity')->label($owned ? 'Allocated / My Held' : 'Allocated'),
                    TextEntry::make('reserved_quantity')->label($owned ? 'Reserved / My Reserved' : 'Reserved'),
                    TextEntry::make('sellable_quantity')->label('Sellable')->state(fn (ProductInventory $record): int => $record->sellableQuantity()),
                    ...($owned ? [] : [TextEntry::make('damaged_quantity')->label('Damaged')]),
                    ...($owned ? [] : [TextEntry::make('total_on_hand')->label('Total on Hand')->state(fn (ProductInventory $record): int => $record->totalOnHand())]),
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
            Section::make('Allocation Breakdown')
                ->description('Total Stock is physical available stock and includes Reserved. Allocated is the sum of recorded ledger balances. Reserved is included in Total Stock and Allocated. Sellable is Total Stock minus Reserved. Any ledger gap is shown; no allocation is inferred.')
                ->visible(fn (ProductInventory $record): bool => app(InventoryAllocationBreakdownReadService::class)->canView(auth()->user(), $record))
                ->schema([
                    TextEntry::make('allocation_reconciliation')->label('Physical / ledger reconciliation')
                        ->state(fn (ProductInventory $record): ?string => app(InventoryAllocationBreakdownReadService::class)->reconciliationText(auth()->user(), $record))
                        ->columnSpanFull(),
                    TextEntry::make('allocation_breakdown')->label('Employee, team, and System / Unallocated balances')
                        ->state(fn (ProductInventory $record): ?string => app(InventoryAllocationBreakdownReadService::class)->linesText(auth()->user(), $record))
                        ->columnSpanFull(),
                ])->columns(1),
        ]);
    }

    private static function canViewFinancials(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(InventoryAuthorization::class)->allows($user, InventoryPermission::ViewFinancials);
    }
}
