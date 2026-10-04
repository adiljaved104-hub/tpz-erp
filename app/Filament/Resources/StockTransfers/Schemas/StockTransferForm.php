<?php

namespace App\Filament\Resources\StockTransfers\Schemas;

use App\Enums\InventoryLocationType;
use App\Enums\ProductStatus;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\Warehouse;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class StockTransferForm
{
    public static function configure(Schema $schema): Schema
    {
        $locations = fn (): array => Warehouse::query()->where('status', true)->where('location_type', '!=', InventoryLocationType::Transit)->orderBy('name')->pluck('name', 'id')->all();

        return $schema->components([
            Section::make('Transfer')->columns(2)->schema([
                Select::make('source_warehouse_id')->label('From')->options($locations)->searchable()->required()->live()
                    ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                        if ((int) $state === (int) $get('destination_warehouse_id')) {
                            $set('destination_warehouse_id', null);
                        }
                        $items = $get('items') ?? [];
                        foreach ($items as &$item) {
                            $item['product_id'] = null;
                        }
                        unset($item);
                        $set('items', $items);
                    })
                    ->default(fn (): ?int => Warehouse::query()->where('status', true)->where('is_default', true)->value('id')),
                Select::make('destination_warehouse_id')->label('To')->options(fn (Get $get): array => array_diff_key($locations(), [(int) $get('source_warehouse_id') => true]))->searchable()->required()->different('source_warehouse_id'),
                DatePicker::make('transfer_date')->default(today())->required(),
                Select::make('handled_by_employee_id')->label('Handled By')->options(fn (): array => Employee::query()->where('status', true)->orderBy('name')->get(['id', 'employee_id', 'name'])->mapWithKeys(fn (Employee $employee): array => [$employee->id => "{$employee->employee_id} · {$employee->name}"])->all())->searchable()
                    ->default(fn (): ?int => auth()->user()?->employee?->id),
            ]),
            Section::make('Products')->schema([
                Repeater::make('items')->minItems(1)->defaultItems(1)->addActionLabel('Add Product')->columns(['default' => 1, 'md' => 12])->schema([
                    Select::make('product_id')->label('Product')->options(function (Get $get): array {
                        $sourceId = (int) $get('../../source_warehouse_id');

                        if ($sourceId < 1) {
                            return [];
                        }

                        return Product::query()->where('status', ProductStatus::Active)
                            ->whereHas('inventories', fn ($inventories) => $inventories->where('warehouse_id', $sourceId)->whereRaw('available_quantity - reserved_quantity > 0'))
                            ->orderBy('name')->get(['id', 'sku', 'name'])
                            ->mapWithKeys(fn (Product $product): array => [$product->id => "{$product->sku} · {$product->name}"])->all();
                    })->searchable()->wrapOptionLabels()->required()->distinct()->live()->columnSpan(['default' => 1, 'md' => 7]),
                    TextInput::make('quantity')->numeric()->integer()->minValue(1)->default(1)->required()->columnSpan(['default' => 1, 'md' => 2]),
                    Placeholder::make('stock_context')->label('Source Stock Context')->content(function ($get): string {
                        $productId = $get('product_id');
                        $sourceId = $get('../../source_warehouse_id');
                        if (! $productId || ! $sourceId) {
                            return 'Select From location and Product.';
                        }
                        $inventory = ProductInventory::query()->select(['available_quantity', 'reserved_quantity'])->where('product_id', $productId)->where('warehouse_id', $sourceId)->first();

                        return $inventory ? "Available {$inventory->available_quantity} · Reserved {$inventory->reserved_quantity} · Sellable {$inventory->sellableQuantity()}" : 'No stock at source.';
                    })->columnSpan(['default' => 1, 'md' => 3]),
                ]),
            ]),
            Textarea::make('notes')->maxLength(5000)->columnSpanFull(),
        ]);
    }
}
