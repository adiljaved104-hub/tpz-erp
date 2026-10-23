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
use App\Services\Inventory\EmployeeOwnedInventoryReadService;
use App\Services\Inventory\InventoryAllocationBreakdownReadService;
use App\Services\Inventory\WeightedAverageCostCalculator;
use App\Services\Mobile\StockStatus;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ProductInventoriesTable
{
    public static function configure(Table $table): Table
    {
        $owned = app(EmployeeOwnedInventoryReadService::class)->isEmployeeView(auth()->user());
        $available = app(EmployeeOwnedInventoryReadService::class)->quantityColumn(auth()->user(), 'available_quantity', locationView: true);
        $reserved = app(EmployeeOwnedInventoryReadService::class)->quantityColumn(auth()->user(), 'reserved_quantity', locationView: true);

        return $table->columns([
            TextColumn::make('product.sku')->label('SKU')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('product.name')->label('Product')->searchable()->sortable()->limit(52)->tooltip(fn (ProductInventory $record): string => $record->product->name),
            TextColumn::make('warehouse.name')->label('Warehouse')->sortable()->toggleable(),
            ViewColumn::make('stock_held_by')
                ->label('Stock Held By')
                ->state(fn (ProductInventory $record): array => app(InventoryAllocationBreakdownReadService::class)
                    ->stockHolderLines(auth()->user(), $record)
                    ->map(fn (array $line): array => ['label' => $line['label'], 'allocated' => $line['allocated']])
                    ->all())
                ->view('filament.tables.columns.stock-held-by')
                ->width('22rem')
                ->extraCellAttributes(['class' => 'align-top']),
            ...($owned ? [TextColumn::make('visibility_reason')->label('Visible Because')->state(fn (ProductInventory $record): string => match (true) {
                (bool) $record->warehouse_responsibility_visible => 'Authorized warehouse stock',
                (bool) $record->authorized_stock_visible => 'Operational responsibility stock',
                (bool) $record->responsibility_zero_stock => 'Responsibility scope · Out of Stock',
                default => 'My allocation',
            })->toggleable()] : []),
            TextColumn::make('available_quantity')->label($owned ? 'Visible Stock' : 'Total Stock')->tooltip($owned ? 'Authorized warehouse or operational rows show physical stock. Other rows show only your own allocation. Reserved is included.' : 'Physical available stock, including Reserved.')->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw($available.' '.$direction))->toggleable(),
            TextColumn::make('allocated_quantity')->label($owned ? 'Recorded Allocated' : 'Allocated')->tooltip($owned ? 'Authorized physical rows show the combined ledger quantity without private holder details. Other rows show only your allocation. Reserved is included.' : 'Sum of recorded Inventory Allocation Ledger balances; Reserved is included.')->sortable()->toggleable(),
            ...($owned ? [TextColumn::make('my_allocated_quantity')->label('My Allocated')->tooltip('Your own recorded allocation within this inventory row.')->sortable()->toggleable()] : []),
            TextColumn::make('reserved_quantity')->label('Reserved')->tooltip($owned ? 'Authorized physical rows show physical Reserved. Other rows show only your own Reserved.' : 'Reserved is a subset of Total Stock and Allocated; do not add it again.')->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw($reserved.' '.$direction))->toggleable(),
            TextColumn::make('sellable_quantity')->label('Sellable')->tooltip($owned ? 'Visible Stock minus its corresponding Reserved quantity. This does not grant permission to consume other allocations.' : 'Total Stock minus Reserved.')->state(fn (ProductInventory $record): int => $record->sellableQuantity())->weight('bold')->toggleable(),
            ...($owned ? [] : [TextColumn::make('damaged_quantity')->label('Damaged')->sortable()->toggleable()]),
            ...($owned ? [] : [TextColumn::make('total_on_hand')->label('Total on Hand')->state(fn (ProductInventory $record): int => $record->totalOnHand())->toggleable()]),
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
            SelectFilter::make('brand')->label('Brand')->options(fn (): array => self::scopedOptions('product.brandRelation', 'name'))
                ->searchable()
                ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $query, $id): Builder => $query->whereHas('product', fn (Builder $product): Builder => $product->where('brand_id', $id)))),
            SelectFilter::make('warehouse_id')->label('Warehouse')->options(fn (): array => self::scopedOptions('warehouse', 'name'))->searchable(),
            SelectFilter::make('stock_holder')->label('Stock Holder')
                ->options(fn (): array => app(InventoryAllocationBreakdownReadService::class)->stockHolderFilterOptions(auth()->user()))
                ->searchable()
                ->query(fn (Builder $query, array $data): Builder => $query->when(
                    $data['value'] ?? null,
                    fn (Builder $query, $accountId): Builder => $query->whereIn(
                        'product_inventories.id',
                        app(InventoryAllocationBreakdownReadService::class)->inventoryIdsForHolder(auth()->user(), (int) $accountId),
                    ),
                )),
            SelectFilter::make('product_id')->label('Product')->options(fn (): array => self::scopedOptions('product', 'name'))->searchable(),
            SelectFilter::make('category')->label('Category')->options(fn (): array => self::scopedOptions('product.categoryRelation', 'name'))
                ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $query, $id): Builder => $query->whereHas('product', fn (Builder $product): Builder => $product->where('category_id', $id)))),
            SelectFilter::make('stock_status')->label('Product stock status')->options(['in_stock' => 'In Stock', 'low_stock' => 'Low Stock', 'out_of_stock' => 'Out of Stock'])
                ->query(function (Builder $query, array $data): Builder {
                    $status = $data['value'] ?? null;
                    if (! in_array($status, ['in_stock', 'low_stock', 'out_of_stock'], true)) {
                        return $query;
                    }
                    $products = app(EmployeeOwnedInventoryReadService::class)->locationInventories(auth()->user())->reorder()
                        ->select('product_id')->groupBy('product_id');
                    $read = app(EmployeeOwnedInventoryReadService::class);
                    $sum = 'SUM(COALESCE('.$read->quantityColumn(auth()->user(), 'available_quantity', locationView: true).', 0) - COALESCE('.$read->quantityColumn(auth()->user(), 'reserved_quantity', locationView: true).', 0))';
                    match ($status) {
                        'low_stock' => $products->havingRaw("{$sum} > 0 AND {$sum} <= ?", [app(StockStatus::class)->low()]),
                        'out_of_stock' => $products->havingRaw("{$sum} <= 0"),
                        default => $products->havingRaw("{$sum} > ?", [app(StockStatus::class)->low()]),
                    };

                    return $query->whereIn('product_id', $products);
                }),
            Filter::make('sellable')->label('Sellable > 0')->query(fn (Builder $query): Builder => $query->whereRaw('('.$available.') > ('.$reserved.')')),
            Filter::make('reserved')->label('Reserved > 0')->query(fn (Builder $query): Builder => $query->whereRaw('('.$reserved.') > 0')),
            ...($owned ? [] : [Filter::make('damaged')->label('Damaged > 0')->query(fn (Builder $query): Builder => $query->where('damaged_quantity', '>', 0))]),
        ])->filtersLayout(FiltersLayout::AboveContent)
            ->filtersFormColumns(3)
            ->filtersFormSchema(fn (array $filters): array => [
                $filters['brand'],
                $filters['warehouse_id'],
                $filters['stock_holder'],
                Section::make('Advanced Filters')
                    ->schema(array_values(array_intersect_key($filters, array_flip([
                        'product_id',
                        'category',
                        'stock_status',
                        'sellable',
                        'reserved',
                        'damaged',
                    ]))))
                    ->columns(3)
                    ->collapsible()
                    ->collapsed()
                    ->columnSpanFull(),
            ])
            ->stackedOnMobile()
            ->recordActions([
                ViewAction::make(),
                self::quantityAction('reserve', 'Reserve', InventoryPermission::Reserve, fn (ProductInventory $record, array $data) => app(ReserveInventory::class)->handle(new ReserveInventoryData($record->id, (int) $data['quantity'], $data['reason'], (string) Str::uuid()), auth()->user())),
                self::quantityAction('markDamaged', 'Mark Damaged', InventoryPermission::MarkDamaged, fn (ProductInventory $record, array $data) => app(MoveInventoryToDamaged::class)->handle(new MoveToDamagedData($record->id, (int) $data['quantity'], $data['reason'], (string) Str::uuid()), auth()->user())),
                self::quantityAction('restoreDamaged', 'Restore Damaged', InventoryPermission::RestoreDamaged, fn (ProductInventory $record, array $data) => app(RestoreDamagedInventory::class)->handle(new RestoreDamagedData($record->id, (int) $data['quantity'], $data['reason'], (string) Str::uuid()), auth()->user())),
            ])->toolbarActions([])
            ->emptyStateHeading($owned ? 'No inventory found in your authorized scope.' : 'No Inventory balances found in your authorized scope.');
    }

    private static function scopedOptions(string $relation, string $label): array
    {
        return app(EmployeeOwnedInventoryReadService::class)->locationInventories(auth()->user())->with(['product:id,name,sku,brand_id,category_id', $relation])->get()
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
