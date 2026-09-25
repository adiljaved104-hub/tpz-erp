<?php

namespace App\Filament\Resources\StockRequests\Schemas;

use App\Enums\StockRequestPurpose;
use App\Models\Order;
use App\Models\ProductInventory;
use App\Services\Inventory\StockRequestService;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class StockRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Request Details')->columns(2)->schema([
                Select::make('purpose')
                    ->options(collect(StockRequestPurpose::cases())->mapWithKeys(fn (StockRequestPurpose $purpose): array => [$purpose->value => $purpose->getLabel()])->all())
                    ->required()->live()->default(StockRequestPurpose::ForOrder->value),
                Select::make('order_id')->label('Order')
                    ->visible(fn (Get $get): bool => $get('purpose') === StockRequestPurpose::ForOrder->value)
                    ->required(fn (Get $get): bool => $get('purpose') === StockRequestPurpose::ForOrder->value)
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => app(StockRequestService::class)->searchOrders(auth()->user(), $search)
                        ->mapWithKeys(fn (Order $order): array => [$order->id => trim("{$order->reference} · {$order->external_order_number}", ' ·')])->all())
                    ->getOptionLabelUsing(function ($value): ?string {
                        $order = Order::query()->select(['id', 'reference', 'external_order_number'])->find($value);

                        return $order === null ? null : trim("{$order->reference} · {$order->external_order_number}", ' ·');
                    }),
                Textarea::make('reason')->required()->minLength(5)->maxLength(2000)->rows(3)->columnSpanFull()
                    ->helperText('Explain why the stock is required. Creating this request does not move or reserve stock.'),
            ]),
            Section::make('Products')->description('System / Unassigned stock is shown separately and is not proposed as an Employee/Team transfer source.')->schema([
                Repeater::make('items')->minItems(1)->maxItems(100)->defaultItems(1)->addActionLabel('Add Product')->columns(3)->schema([
                    Select::make('product_inventory_id')->label('Product / Warehouse')->required()->distinct()->searchable()->live()
                        ->getSearchResultsUsing(fn (string $search): array => app(StockRequestService::class)->searchInventories(auth()->user(), $search)
                            ->mapWithKeys(fn (ProductInventory $inventory): array => [$inventory->id => app(StockRequestService::class)->inventoryLabel($inventory)])->all())
                        ->getOptionLabelUsing(function ($value): ?string {
                            $inventory = ProductInventory::query()->with(['product', 'warehouse'])->find($value);

                            return $inventory === null ? null : app(StockRequestService::class)->inventoryLabel($inventory);
                        })->columnSpan(2),
                    TextInput::make('quantity')->numeric()->integer()->minValue(1)->required()->default(1),
                    Placeholder::make('source_availability')->label('Proposed Available Sources')->content(function (Get $get): string {
                        $inventoryId = (int) $get('product_inventory_id');
                        if ($inventoryId < 1) {
                            return 'Select a product to see Employee/Team source availability.';
                        }
                        $availability = app(StockRequestService::class)->sourceAvailability($inventoryId);
                        $holders = $availability['holders']->map(fn (array $source): string => "{$source['label']}: {$source['available_quantity']}")->join(' · ');

                        return ($holders === '' ? 'Employee/Team sources: none' : $holders)." · System / Unassigned: {$availability['system_unassigned']}";
                    })->columnSpanFull(),
                ]),
            ]),
        ]);
    }
}
