<?php

namespace App\Filament\Resources\WebSalesOrders\Schemas;

use App\Enums\ProductMatchContext;
use App\Enums\WebSalesChannel;
use App\Enums\WebSalesDeliveryType;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\SalesConfiguration;
use App\Models\UpgradeRecipe;
use App\Models\User;
use App\Services\DefaultWarehouseService;
use App\Services\Orders\WebSalesReadService;
use App\Services\ProductIntelligence\ProductSearchOptions;
use App\Support\AedMoney;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class WebSalesOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Customer')->description('Main Warehouse and the logged-in Sales Employee are selected automatically.')->schema([
                TextInput::make('customer_name')->label('Customer Name')->required()->maxLength(255),
                TextInput::make('customer_phone')->label('WhatsApp / Phone')->required()->maxLength(40)
                    ->placeholder('+971 50 123 4567')->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, Get $get, Set $set) => self::suggestCustomer($state, $get, $set)),
                Select::make('web_sales_channel')->label('Channel')->options(WebSalesChannel::class)
                    ->default(WebSalesChannel::WhatsApp->value)->required()->native(false),
                Hidden::make('idempotency_key')->default(fn (): string => (string) str()->uuid()),
            ])->columns(2)->compact(),
            Section::make('Products')->schema([
                Repeater::make('items')->schema([
                    Select::make('product_id')->label('Product')->placeholder('Search by SKU or product name')
                        ->searchable()->searchPrompt('Type at least 2 characters to search available products.')
                        ->noSearchResultsMessage('No sellable products found in Main Warehouse.')
                        ->getSearchResultsUsing(fn (string $search): array => self::productOptions($search))
                        ->getOptionLabelUsing(fn ($value): ?string => self::productLabel((int) $value))
                        ->required()->live()
                        ->afterStateUpdated(fn ($state, Set $set) => self::productChanged($state, $set))
                        ->helperText(fn (Get $get): string => 'Only products with available sellable Main Warehouse stock are shown.'.($get('stock_context') ? ' '.$get('stock_context') : '')),
                    TextInput::make('quantity')->numeric()->integer()->minValue(1)->default(1)->required()->live(onBlur: true),
                    TextInput::make('selling_price')->label('Selling Price')->prefix('AED')->required()
                        ->rule('regex:/^\d{1,13}(?:\.\d{1,2})?$/')->live(onBlur: true),
                    Placeholder::make('line_total')->label('Line Total')->content(fn (Get $get): string => self::lineTotal($get)),
                    Toggle::make('upgraded_configuration')->label('Upgraded Configuration')->live()
                        ->afterStateUpdated(function ($state, Set $set): void {
                            if (! $state) {
                                $set('sales_configuration_id', null);
                                $set('upgrade_recipe_id', null);
                            }
                        }),
                    Select::make('sales_configuration_id')->label('Valid Configuration')->searchable()->live()
                        ->getSearchResultsUsing(fn (string $search, Get $get): array => self::configurationOptions((int) $get('product_id'), $search))
                        ->getOptionLabelUsing(fn ($value): ?string => SalesConfiguration::query()->whereKey((int) $value)->value('display_name'))
                        ->required(fn (Get $get): bool => (bool) $get('upgraded_configuration'))
                        ->visible(fn (Get $get): bool => (bool) $get('upgraded_configuration'))
                        ->afterStateUpdated(function ($state, Set $set): void {
                            $set('upgrade_recipe_id', array_key_first(self::recipeOptions((int) $state)));
                        }),
                    Select::make('upgrade_recipe_id')->label('Build Method')
                        ->options(fn (Get $get): array => self::recipeOptions((int) $get('sales_configuration_id')))
                        ->required(fn (Get $get): bool => (bool) $get('upgraded_configuration'))
                        ->visible(fn (Get $get): bool => (bool) $get('upgraded_configuration')),
                    Placeholder::make('upgrade_summary')->label('Configuration')
                        ->content(fn (Get $get): string => self::upgradeSummary((int) $get('sales_configuration_id'), (int) $get('upgrade_recipe_id')))
                        ->visible(fn (Get $get): bool => (bool) $get('upgraded_configuration')),
                    Hidden::make('stock_context')->dehydrated(false),
                ])->columns(4)->compact()->reorderable(false)->minItems(1)->defaultItems(1)->addActionLabel('Add Product / Configuration')->columnSpanFull(),
            ])->compact(),
            Section::make('Delivery')->schema([
                Select::make('delivery_type')->label('Delivery')->options(WebSalesDeliveryType::class)
                    ->default(WebSalesDeliveryType::Courier->value)->required()->native(false)->live()
                    ->afterStateUpdated(function ($state, Set $set): void {
                        if (self::deliveryValue($state) === WebSalesDeliveryType::ShopPickup->value) {
                            $set('courier_name', null);
                            $set('tracking_number', null);
                        }
                    }),
                TextInput::make('courier_name')->label('Courier Name')->maxLength(100)
                    ->required(fn (Get $get): bool => self::deliveryValue($get('delivery_type')) === WebSalesDeliveryType::Courier->value)
                    ->visible(fn (Get $get): bool => self::deliveryValue($get('delivery_type')) === WebSalesDeliveryType::Courier->value),
                TextInput::make('tracking_number')->label('Tracking / AWB')->maxLength(100)
                    ->visible(fn (Get $get): bool => self::deliveryValue($get('delivery_type')) === WebSalesDeliveryType::Courier->value),
                Textarea::make('notes')->maxLength(5000)->rows(2)->columnSpanFull(),
            ])->columns(3)->compact(),
        ]);
    }

    private static function productOptions(string $search): array
    {
        $search = trim($search);
        $user = auth()->user();
        if (! $user instanceof User || mb_strlen($search) < 2) {
            return [];
        }
        $warehouse = app(DefaultWarehouseService::class)->operationalDefault();
        if ($warehouse->code !== 'MAIN') {
            return [];
        }

        return app(ProductSearchOptions::class)->search(
            $search,
            ProductMatchContext::WebSales,
            $user,
            $warehouse->id,
        );
    }

    private static function productLabel(int $id): ?string
    {
        return self::productOptions((string) (Product::query()->products()->find($id, ['id', 'sku'])?->sku ?? ''))[$id] ?? null;
    }

    private static function productChanged(mixed $state, Set $set): void
    {
        $product = $state ? Product::query()->products()->find((int) $state, ['id', 'selling_price']) : null;
        if ($product === null) {
            $set('stock_context', null);

            return;
        }
        $warehouse = app(DefaultWarehouseService::class)->operationalDefault();
        $inventory = ProductInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
        $sellable = $inventory?->sellableQuantity() ?? 0;
        $set('stock_context', "Available: {$sellable}");
        $set('sales_configuration_id', null);
        $set('upgrade_recipe_id', null);
        if ($product->selling_price !== null) {
            $set('selling_price', $product->selling_price);
        }
    }

    private static function configurationOptions(int $productId, string $search): array
    {
        return SalesConfiguration::query()->current()->where('product_id', $productId)->where('active', true)
            ->where('display_name', 'like', '%'.trim($search).'%')->orderBy('display_name')->limit(30)
            ->pluck('display_name', 'id')->all();
    }

    private static function recipeOptions(int $configurationId): array
    {
        return UpgradeRecipe::query()->where('sales_configuration_id', $configurationId)->where('active', true)
            ->orderByDesc('preferred')->orderBy('priority')->limit(30)->get(['id', 'name', 'preferred'])
            ->mapWithKeys(fn (UpgradeRecipe $recipe): array => [$recipe->id => $recipe->name.($recipe->preferred ? ' · Preferred' : '')])->all();
    }

    private static function upgradeSummary(int $configurationId, int $recipeId): string
    {
        $configuration = SalesConfiguration::query()->find($configurationId, ['display_name']);
        $recipe = UpgradeRecipe::query()->find($recipeId, ['name']);

        return $configuration === null ? 'Select a valid configuration.' : $configuration->display_name.($recipe ? " · {$recipe->name}" : '');
    }

    private static function suggestCustomer(mixed $state, Get $get, Set $set): void
    {
        if (! filled($state) || filled($get('customer_name')) || ! auth()->user() instanceof User) {
            return;
        }
        $normalized = (str_starts_with(trim((string) $state), '+') ? '+' : '').preg_replace('/\D+/', '', (string) $state);
        $order = app(WebSalesReadService::class)->scoped(auth()->user())->where('customer_phone', $normalized)->latest('id')->first(['customer_name']);
        if ($order?->customer_name) {
            $set('customer_name', $order->customer_name);
        }
    }

    private static function lineTotal(Get $get): string
    {
        $price = $get('selling_price');

        return filled($price) && function_exists('bcmul') ? AedMoney::format(bcmul((string) max(0, (int) $get('quantity')), (string) $price, 2)) : 'AED 0.00';
    }

    private static function deliveryValue(mixed $value): mixed
    {
        return $value instanceof WebSalesDeliveryType ? $value->value : $value;
    }
}
