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
use App\Services\Inventory\InventoryReadService;
use App\Services\Inventory\WeightedAverageCostCalculator;
use App\Services\Mobile\StockStatus;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ProductInventoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('product.sku')->label('SKU')->searchable()->toggleable(),
            TextColumn::make('product.name')->label('Product')->searchable()->sortable()->limit(52)->tooltip(fn (ProductInventory $record): string => $record->product->name),
            TextColumn::make('warehouse.name')->label('Warehouse')->sortable()->toggleable(),
            TextColumn::make('available_quantity')->label('Available')->tooltip('Available includes Reserved.')->sortable()->toggleable(),
            TextColumn::make('reserved_quantity')->label('Reserved')->sortable()->toggleable(),
            TextColumn::make('sellable_quantity')->label('Sellable')->state(fn (ProductInventory $record): int => $record->sellableQuantity())->weight('bold')->toggleable(),
            TextColumn::make('damaged_quantity')->label('Damaged')->sortable()->toggleable(),
            TextColumn::make('total_on_hand')->label('Total on Hand')->state(fn (ProductInventory $record): int => $record->totalOnHand())->toggleable(),
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
        ])->filters([
            SelectFilter::make('warehouse_id')->label('Warehouse')->options(fn (): array => self::scopedOptions('warehouse', 'name'))->searchable(),
            SelectFilter::make('product_id')->label('Product')->options(fn (): array => self::scopedOptions('product', 'name'))->searchable(),
            SelectFilter::make('brand')->label('Brand')->options(fn (): array => self::scopedOptions('product.brandRelation', 'name'))
                ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $query, $id): Builder => $query->whereHas('product', fn (Builder $product): Builder => $product->where('brand_id', $id)))),
            SelectFilter::make('category')->label('Category')->options(fn (): array => self::scopedOptions('product.categoryRelation', 'name'))
                ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $query, $id): Builder => $query->whereHas('product', fn (Builder $product): Builder => $product->where('category_id', $id)))),
            SelectFilter::make('stock_status')->label('Product stock status')->options(['in_stock' => 'In Stock', 'low_stock' => 'Low Stock', 'out_of_stock' => 'Out of Stock'])
                ->query(function (Builder $query, array $data): Builder {
                    $status = $data['value'] ?? null;
                    if (! in_array($status, ['in_stock', 'low_stock', 'out_of_stock'], true)) {
                        return $query;
                    }
                    $products = app(InventoryReadService::class)->inventories(auth()->user())->reorder()
                        ->select('product_id')->groupBy('product_id');
                    $sum = 'SUM(available_quantity - reserved_quantity)';
                    match ($status) {
                        'low_stock' => $products->havingRaw("{$sum} > 0 AND {$sum} <= ?", [app(StockStatus::class)->low()]),
                        'out_of_stock' => $products->havingRaw("{$sum} <= 0"),
                        default => $products->havingRaw("{$sum} > ?", [app(StockStatus::class)->low()]),
                    };

                    return $query->whereIn('product_id', $products);
                }),
            Filter::make('sellable')->label('Sellable > 0')->query(fn (Builder $query): Builder => $query->whereColumn('available_quantity', '>', 'reserved_quantity')),
            Filter::make('reserved')->label('Reserved > 0')->query(fn (Builder $query): Builder => $query->where('reserved_quantity', '>', 0)),
            Filter::make('damaged')->label('Damaged > 0')->query(fn (Builder $query): Builder => $query->where('damaged_quantity', '>', 0)),
        ])->recordActions([
            ViewAction::make(),
            self::quantityAction('reserve', 'Reserve', InventoryPermission::Reserve, fn (ProductInventory $record, array $data) => app(ReserveInventory::class)->handle(new ReserveInventoryData($record->id, (int) $data['quantity'], $data['reason'], (string) Str::uuid()), auth()->user())),
            self::quantityAction('markDamaged', 'Mark Damaged', InventoryPermission::MarkDamaged, fn (ProductInventory $record, array $data) => app(MoveInventoryToDamaged::class)->handle(new MoveToDamagedData($record->id, (int) $data['quantity'], $data['reason'], (string) Str::uuid()), auth()->user())),
            self::quantityAction('restoreDamaged', 'Restore Damaged', InventoryPermission::RestoreDamaged, fn (ProductInventory $record, array $data) => app(RestoreDamagedInventory::class)->handle(new RestoreDamagedData($record->id, (int) $data['quantity'], $data['reason'], (string) Str::uuid()), auth()->user())),
        ])->toolbarActions([])
            ->emptyStateHeading('No Inventory balances found in your authorized scope.');
    }

    private static function scopedOptions(string $relation, string $label): array
    {
        return app(InventoryReadService::class)->inventories(auth()->user())->with(['product:id,name,sku,brand_id,category_id', $relation])->get()
            ->map(fn (ProductInventory $inventory) => data_get($inventory, $relation))->filter()->unique('id')->sortBy($label)->pluck($label, 'id')->all();
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
