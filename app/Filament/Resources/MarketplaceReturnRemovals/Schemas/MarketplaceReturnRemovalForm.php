<?php

namespace App\Filament\Resources\MarketplaceReturnRemovals\Schemas;

use App\Enums\InventoryLocationType;
use App\Enums\MarketplaceRemovalSourceStockType;
use App\Models\CustomerReturnItem;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Returns\MarketplaceReturnService;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class MarketplaceReturnRemovalForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Marketplace Removal')->columns(2)->schema([
                Select::make('marketplace_platform_id')->label('Marketplace Platform')->options(fn () => MarketplacePlatform::query()->active()->orderBy('name')->pluck('name', 'id'))->required()->searchable()->live()->afterStateUpdated(fn (Set $set) => $set('source_warehouse_id', null)),
                Select::make('source_warehouse_id')->label('Source Marketplace Location')->options(fn (Get $get) => Warehouse::query()->active()->where('location_type', InventoryLocationType::MarketplaceFulfilment)->where('marketplace_platform_id', $get('marketplace_platform_id'))->orderBy('name')->pluck('name', 'id'))->required()->searchable()->live()->disabled(fn (Get $get): bool => ! $get('marketplace_platform_id')),
                Select::make('destination_warehouse_id')->label('Destination Company Location')->options(fn () => Warehouse::query()->active()->whereNotIn('location_type', [InventoryLocationType::Transit, InventoryLocationType::MarketplaceFulfilment])->orderByDesc('is_default')->orderBy('name')->pluck('name', 'id'))->default(fn () => Warehouse::query()->where('is_default', true)->value('id'))->required()->searchable(),
                TextInput::make('external_removal_reference')->label('External Removal Reference')->maxLength(255),
                Textarea::make('notes')->maxLength(5000)->columnSpanFull(),
            ]),
            Section::make('Removal Items')->schema([
                Repeater::make('items')->minItems(1)->defaultItems(1)->addActionLabel('Add Product')->schema([
                    Select::make('product_id')->label('Product')->options(function (Get $get): array {
                        $warehouseId = $get('../../source_warehouse_id');
                        if (! $warehouseId) {
                            return [];
                        }

                        return Product::query()->products()->whereHas('inventories', fn ($query) => $query->where('warehouse_id', $warehouseId))->active()->orderBy('name')->get(['id', 'sku', 'name'])->mapWithKeys(fn (Product $product): array => [$product->id => "{$product->sku} - {$product->name}"])->all();
                    })->required()->searchable()->live()->afterStateUpdated(fn (Set $set) => $set('quantity', null)),
                    Select::make('source_stock_type')->label('Source Stock Type')->options(collect(MarketplaceRemovalSourceStockType::cases())->mapWithKeys(fn ($type) => [$type->value => $type->getLabel()]))->required()->live()->afterStateUpdated(function (Set $set, mixed $state): void {
                        $set('quantity', null);
                        if (self::normalizeSourceStockType($state) === MarketplaceRemovalSourceStockType::Sellable) {
                            $set('customer_return_item_id', null);
                        }
                    }),
                    Placeholder::make('available_removable')->label('Available Removable Qty')->content(fn (Get $get): int => self::availableQuantity($get)),
                    Placeholder::make('availability_warning')
                        ->hiddenLabel()
                        ->content(fn (Get $get): string => self::availabilityWarning($get))
                        ->visible(fn (Get $get): bool => self::hasAvailabilityContext($get) && self::availableQuantity($get) === 0)
                        ->columnSpan(2),
                    TextInput::make('quantity')
                        ->label('Removal Qty')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(fn (Get $get): ?int => self::hasAvailabilityContext($get) ? self::availableQuantity($get) : null)
                        ->validationMessages([
                            'max' => fn (Get $get): string => 'Only '.self::availableQuantity($get).' units are available for removal.',
                        ])
                        ->helperText(fn (Get $get): ?string => self::hasAvailabilityContext($get) && self::availableQuantity($get) > 0
                            ? self::availableQuantity($get).' units currently available for removal.'
                            : null)
                        ->disabled(fn (Get $get): bool => self::hasAvailabilityContext($get) && self::availableQuantity($get) === 0)
                        ->dehydrated()
                        ->required(),
                    Select::make('customer_return_item_id')->label('Customer Return Item')->options(function (Get $get): array {
                        $productId = $get('product_id');
                        $warehouseId = $get('../../source_warehouse_id');
                        if (! $productId || ! $warehouseId) {
                            return [];
                        }

                        return CustomerReturnItem::query()->where('product_id', $productId)->whereHas('customerReturn', fn ($query) => $query->where('fulfillment_warehouse_id', $warehouseId))->whereHas('marketplaceDispositions', fn ($query) => $query->where('result', 'non_sellable'))->with('customerReturn:id,reference')->get()->mapWithKeys(fn ($item): array => [$item->id => "{$item->customerReturn->reference} - {$item->sku_snapshot}"])->all();
                    })->required(fn (Get $get): bool => self::normalizeSourceStockType($get('source_stock_type')) === MarketplaceRemovalSourceStockType::NonSellable)->visible(fn (Get $get): bool => self::normalizeSourceStockType($get('source_stock_type')) === MarketplaceRemovalSourceStockType::NonSellable)->searchable()->live()->afterStateUpdated(fn (Set $set) => $set('quantity', null)),
                ])->columns(3),
            ]),
        ]);
    }

    private static function availableQuantity(Get $get): int
    {
        $sourceStockType = self::normalizeSourceStockType($get('source_stock_type'));
        if (! self::hasAvailabilityContext($get) || $sourceStockType === null) {
            return 0;
        }

        return app(MarketplaceReturnService::class)->availableRemovalQuantity(
            (int) $get('../../source_warehouse_id'),
            (int) $get('product_id'),
            $sourceStockType,
            filled($get('customer_return_item_id')) ? (int) $get('customer_return_item_id') : null,
        );
    }

    private static function hasAvailabilityContext(Get $get): bool
    {
        return filled($get('../../source_warehouse_id'))
            && filled($get('product_id'))
            && self::normalizeSourceStockType($get('source_stock_type')) !== null;
    }

    private static function availabilityWarning(Get $get): string
    {
        $type = self::normalizeSourceStockType($get('source_stock_type'));
        $location = Warehouse::query()->whereKey($get('../../source_warehouse_id'))->value('name') ?? 'the selected Marketplace location';

        return "No removable {$type?->getLabel()} stock is available at {$location}.";
    }

    private static function normalizeSourceStockType(mixed $value): ?MarketplaceRemovalSourceStockType
    {
        if ($value instanceof MarketplaceRemovalSourceStockType) {
            return $value;
        }

        return is_string($value) ? MarketplaceRemovalSourceStockType::tryFrom($value) : null;
    }
}
