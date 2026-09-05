<?php

namespace App\Filament\Resources\ProductInventories\Tables;

use App\Actions\Inventory\MoveInventoryToDamaged;
use App\Actions\Inventory\ReserveInventory;
use App\Actions\Inventory\RestoreDamagedInventory;
use App\DTOs\Inventory\MoveToDamagedData;
use App\DTOs\Inventory\ReserveInventoryData;
use App\DTOs\Inventory\RestoreDamagedData;
use App\Enums\InventoryPermission;
use App\Filament\Tables\Columns\PurchaseCostHistoryColumn;
use App\Models\ProductInventory;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Inventory\WeightedAverageCostCalculator;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ProductInventoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('product.sku')->label('SKU')->searchable(),
            TextColumn::make('product.name')->label('Product')->searchable()->sortable()->limit(52)->tooltip(fn (ProductInventory $record): string => $record->product->name),
            TextColumn::make('warehouse.name')->label('Warehouse')->sortable(),
            TextColumn::make('available_quantity')->label('Available')->tooltip('Available includes Reserved.')->sortable(),
            TextColumn::make('reserved_quantity')->label('Reserved')->sortable(),
            TextColumn::make('sellable_quantity')->label('Sellable')->state(fn (ProductInventory $record): int => $record->sellableQuantity())->weight('bold'),
            TextColumn::make('damaged_quantity')->label('Damaged')->sortable(),
            TextColumn::make('total_on_hand')->label('Total on Hand')->state(fn (ProductInventory $record): int => $record->totalOnHand()),
            ...(self::allowed(InventoryPermission::ViewFinancials) ? [
                TextColumn::make('average_cost')->label('Average Cost')->money('AED', decimalPlaces: 2)->placeholder('No cost history'),
                TextColumn::make('inventory_value')
                    ->label('Inventory Value')
                    ->state(fn (ProductInventory $record): ?string => $record->average_cost === null ? null : app(WeightedAverageCostCalculator::class)->inventoryValue($record->totalOnHand(), $record->average_cost))
                    ->money('AED'),
            ] : []),
            ...(PurchaseCostHistoryColumn::allowed() ? [
                PurchaseCostHistoryColumn::make(fn (ProductInventory $inventory): int => $inventory->product_id),
            ] : []),
        ])->recordActions([
            ViewAction::make(),
            self::quantityAction('reserve', 'Reserve', InventoryPermission::Reserve, fn (ProductInventory $record, array $data) => app(ReserveInventory::class)->handle(new ReserveInventoryData($record->id, (int) $data['quantity'], $data['reason'], (string) Str::uuid()), auth()->user())),
            self::quantityAction('markDamaged', 'Mark Damaged', InventoryPermission::MarkDamaged, fn (ProductInventory $record, array $data) => app(MoveInventoryToDamaged::class)->handle(new MoveToDamagedData($record->id, (int) $data['quantity'], $data['reason'], (string) Str::uuid()), auth()->user())),
            self::quantityAction('restoreDamaged', 'Restore Damaged', InventoryPermission::RestoreDamaged, fn (ProductInventory $record, array $data) => app(RestoreDamagedInventory::class)->handle(new RestoreDamagedData($record->id, (int) $data['quantity'], $data['reason'], (string) Str::uuid()), auth()->user())),
        ])->toolbarActions([])
            ->emptyStateHeading('No Inventory balances found in your authorized scope.');
    }

    private static function quantityAction(string $name, string $label, InventoryPermission $permission, callable $callback): Action
    {
        return Action::make($name)
            ->label($label)
            ->requiresConfirmation()
            ->schema([
                TextInput::make('quantity')->numeric()->integer()->minValue(1)->required(),
                Textarea::make('reason')->required()->maxLength(2000),
            ])
            ->visible(fn (ProductInventory $record): bool => self::allowed($permission, $record))
            ->authorize(fn (ProductInventory $record): bool => self::allowed($permission, $record))
            ->action($callback);
    }

    private static function allowed(InventoryPermission $permission, ?ProductInventory $inventory = null): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(InventoryAuthorization::class)->allows($user, $permission, $inventory);
    }
}
