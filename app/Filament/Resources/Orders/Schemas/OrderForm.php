<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderPermission;
use App\Enums\ProductMatchContext;
use App\Models\Employee;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\SalesConfiguration;
use App\Models\UpgradeRecipe;
use App\Models\User;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Orders\OrderFulfillmentLocationService;
use App\Services\Orders\OrderResponsibilityScopeService;
use App\Services\ProductIntelligence\ProductSearchOptions;
use App\Services\Upgrades\UpgradeRecipeValidationService;
use App\Support\AedMoney;
use Filament\Forms\Components\DatePicker;
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

class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Order')->schema([
                Select::make('marketplace_platform_id')
                    ->label('Platform')
                    ->placeholder('Manual / No Platform')
                    ->options(fn (): array => MarketplacePlatform::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()->live()
                    ->afterStateUpdated(fn ($state, Get $get, Set $set): mixed => self::platformChanged($state, $get, $set)),
                Select::make('warehouse_id')
                    ->label('Fulfilled From')
                    ->options(fn (Get $get): array => app(OrderFulfillmentLocationService::class)->options(
                        filled($get('marketplace_platform_id')) ? (int) $get('marketplace_platform_id') : null,
                    ))
                    ->default(fn (): int => app(OrderFulfillmentLocationService::class)->defaultId())
                    ->required()
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(fn ($state, Get $get, Set $set): mixed => self::refreshStockContexts($get, $set)),
                TextInput::make('external_order_number')->label('External / Marketplace Order Number')->maxLength(255),
                DatePicker::make('order_date')->default(now())->required(),
                Select::make('handled_by_employee_id')
                    ->label('Handled By')
                    ->options(fn (): array => Employee::query()->where('status', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->default(fn (): ?int => auth()->user()?->employee?->id)
                    ->required()->searchable(),
                Hidden::make('idempotency_key')->default(fn (): string => (string) str()->uuid()),
            ])->columns(2)->compact(),
            Section::make('Products')->schema([
                Repeater::make('items')
                    ->schema([
                        Select::make('product_id')
                            ->label('Product')
                            ->placeholder('Search by SKU or product name')
                            ->searchable()
                            ->wrapOptionLabels()
                            ->searchPrompt('Type at least 2 characters to search available products.')
                            ->noSearchResultsMessage('No sellable products found for the selected warehouse/platform.')
                            ->required()->live()
                            ->getSearchResultsUsing(fn (string $search, Get $get): array => self::productOptions($search, $get))
                            ->getOptionLabelUsing(fn ($value, Get $get): ?string => self::productLabel((int) $value, $get))
                            ->afterStateUpdated(fn ($state, Get $get, Set $set): mixed => self::productChanged($state, $get, $set))
                            ->columnSpanFull()
                            ->helperText(function (Get $get): string {
                                $stockContext = $get->string('stock_context', isNullable: true);

                                return 'Only products with available sellable stock are shown.'.($stockContext === null ? '' : " {$stockContext}");
                            }),
                        TextInput::make('quantity')->numeric()->integer()->minValue(1)->default(1)->required()->live(onBlur: true),
                        TextInput::make('selling_price')->label('Selling Price')->prefix('AED')
                            ->rule('regex:/^\d{1,13}(?:\.\d{1,2})?$/')->required()->live(onBlur: true)
                            ->disabled(fn (): bool => ! self::allowed(OrderPermission::EditSellingPrice)),
                        Placeholder::make('line_total')->label('Line Total')->content(fn (Get $get): string => self::lineTotal($get)),
                        Toggle::make('upgraded_configuration')->label('Upgraded Configuration')->live()
                            ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                if (! $state) {
                                    $set('target_ram_mb', null);
                                    $set('target_storage_total_gb', null);
                                    $set('sales_configuration_id', null);
                                    $set('upgrade_recipe_id', null);

                                    return;
                                }

                                self::refreshConfigurationSelection($get, $set);
                            }),
                        Select::make('target_ram_mb')->label('Target RAM')->placeholder('Any RAM target')
                            ->options(fn (Get $get): array => self::targetRamOptions((int) $get('product_id')))
                            ->visible(fn (Get $get): bool => (bool) $get('upgraded_configuration'))->live()->dehydrated(false)
                            ->afterStateUpdated(fn (Get $get, Set $set): mixed => self::configurationTargetChanged($get, $set))
                            ->columnSpan(['default' => 1, 'xl' => 2]),
                        Select::make('target_storage_total_gb')->label('Target Storage')->placeholder('Any storage target')
                            ->options(fn (Get $get): array => self::targetStorageOptions((int) $get('product_id')))
                            ->visible(fn (Get $get): bool => (bool) $get('upgraded_configuration'))->live()->dehydrated(false)
                            ->afterStateUpdated(fn (Get $get, Set $set): mixed => self::configurationTargetChanged($get, $set))
                            ->columnSpan(['default' => 1, 'xl' => 2]),
                        Select::make('sales_configuration_id')->label('Valid Configuration')
                            ->placeholder('Select a valid configuration')->searchable()->live()->wrapOptionLabels()
                            ->options(fn (Get $get): array => self::matchingConfigurationOptions($get))
                            ->getSearchResultsUsing(fn (string $search, Get $get): array => self::configurationOptions($search, $get))
                            ->getOptionLabelUsing(fn ($value): ?string => SalesConfiguration::query()->whereKey((int) $value)->value('display_name'))
                            ->required(fn (Get $get): bool => (bool) $get('upgraded_configuration'))
                            ->visible(fn (Get $get): bool => (bool) $get('upgraded_configuration') && count(self::matchingConfigurationOptions($get)) !== 1)
                            ->afterStateUpdated(fn ($state, Get $get, Set $set): mixed => self::configurationChanged($state, $get, $set))
                            ->columnSpanFull(),
                        Placeholder::make('selected_configuration_summary')->label('Selected Configuration')
                            ->content(fn (Get $get): string => self::configurationSummary((int) $get('sales_configuration_id')))
                            ->visible(fn (Get $get): bool => (bool) $get('upgraded_configuration') && filled($get('sales_configuration_id')))
                            ->columnSpanFull(),
                        Placeholder::make('suggested_selling_price')->label('Suggested Selling Price')
                            ->content(fn (Get $get): string => self::suggestedPrice((int) $get('product_id'), (int) $get('sales_configuration_id')))
                            ->visible(fn (Get $get): bool => (bool) $get('upgraded_configuration') && self::allowed(OrderPermission::EditSellingPrice))
                            ->columnSpanFull(),
                        Placeholder::make('upgrade_setup_warning')->label('Configuration Status')
                            ->content(fn (Get $get): string => self::configurationWarning($get))
                            ->visible(fn (Get $get): bool => (bool) $get('upgraded_configuration'))
                            ->columnSpanFull(),
                        Section::make('Advanced Configuration')
                            ->description('Build method and technical recipe details.')
                            ->schema([
                                Select::make('upgrade_recipe_id')->label('Build Method')->placeholder('Preferred Build')
                                    ->options(fn (Get $get): array => self::recipeOptions((int) $get('sales_configuration_id')))
                                    ->required(fn (Get $get): bool => (bool) $get('upgraded_configuration')),
                                Placeholder::make('build_summary')->label('Build Details')
                                    ->content(fn (Get $get): string => self::recipeSummary((int) $get('upgrade_recipe_id'))),
                            ])
                            ->columns(['default' => 1, 'lg' => 2])
                            ->collapsible()->collapsed()
                            ->visible(fn (Get $get): bool => (bool) $get('upgraded_configuration'))
                            ->columnSpanFull(),
                        Hidden::make('discount_total')->default('0.00'),
                        Hidden::make('vat_rate')->default('0.0000'),
                        Hidden::make('stock_context')->dehydrated(false),
                    ])
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
                    ->compact()->reorderable(false)->minItems(1)->defaultItems(1)->addActionLabel('Add Product / Configuration')->columnSpanFull(),
            ]),
            Section::make('Notes')->schema([
                Textarea::make('notes')->maxLength(5000),
            ])->collapsed()->collapsible(),
        ]);
    }

    private static function productOptions(string $search, Get $get): array
    {
        $search = trim($search);
        $user = auth()->user();
        $warehouseId = (int) $get('../../warehouse_id');
        $platformId = filled($get('../../marketplace_platform_id')) ? (int) $get('../../marketplace_platform_id') : null;

        if (! $user instanceof User || $warehouseId < 1 || mb_strlen($search) < 2) {
            return [];
        }

        $options = app(ProductSearchOptions::class)->search(
            $search,
            ProductMatchContext::Order,
            $user,
            $warehouseId,
            $platformId,
        );
        $products = Product::query()->whereKey(array_keys($options))->get(['id', 'sku', 'name'])->keyBy('id');

        return collect($options)->map(function (string $label, int $productId) use ($products): string {
            $product = $products->get($productId);
            $firstSeparator = mb_strpos($label, ' · ');
            $secondSeparator = $firstSeparator === false ? false : mb_strpos($label, ' · ', $firstSeparator + 3);

            return $product instanceof Product && $secondSeparator !== false
                ? "{$product->sku} · ".trim($product->name).mb_substr($label, $secondSeparator)
                : $label;
        })->all();
    }

    private static function productLabel(int $id, Get $get): ?string
    {
        $user = auth()->user();
        $warehouseId = (int) $get('../../warehouse_id');
        $platformId = filled($get('../../marketplace_platform_id')) ? (int) $get('../../marketplace_platform_id') : null;

        if (! $user instanceof User || $warehouseId < 1) {
            return null;
        }

        $product = app(OrderResponsibilityScopeService::class)
            ->applyProducts(Product::query()->products()->whereKey($id), $user, $platformId, $warehouseId)
            ->first(['id', 'sku', 'name']);

        if ($product === null) {
            return null;
        }

        $inventory = ProductInventory::query()
            ->where('product_id', $id)
            ->where('warehouse_id', $warehouseId)
            ->first(['available_quantity', 'reserved_quantity']);

        return self::label($product, $inventory?->sellableQuantity() ?? 0);
    }

    private static function label(Product $product, int $sellable): string
    {
        return "{$product->sku} · ".trim($product->name).' · Sellable: '.$sellable;
    }

    private static function loadStockContext(mixed $state, Get $get, Set $set): void
    {
        if (! $state) {
            $set('stock_context', null);

            return;
        }

        $set('stock_context', self::stockContext((int) $state, (int) $get('../../warehouse_id')));
    }

    private static function productChanged(mixed $state, Get $get, Set $set): void
    {
        self::loadStockContext($state, $get, $set);
        $set('target_ram_mb', null);
        $set('target_storage_total_gb', null);
        $set('sales_configuration_id', null);
        $set('upgrade_recipe_id', null);

        if ((bool) $get('upgraded_configuration')) {
            self::refreshConfigurationSelection($get, $set);
        }
    }

    private static function targetRamOptions(int $productId): array
    {
        return SalesConfiguration::query()->current()->where('product_id', $productId)->where('active', true)->whereNotNull('target_ram_mb')
            ->distinct()->orderBy('target_ram_mb')->pluck('target_ram_mb')->mapWithKeys(fn ($mb): array => [(string) $mb => self::capacityLabel((int) $mb, 'MB')])->all();
    }

    private static function targetStorageOptions(int $productId): array
    {
        return SalesConfiguration::query()->current()->where('product_id', $productId)->where('active', true)->whereNotNull('target_storage_total_gb')
            ->distinct()->orderBy('target_storage_total_gb')->pluck('target_storage_total_gb')
            ->mapWithKeys(fn ($gb): array => [(string) $gb => self::capacityLabel((float) $gb, 'GB')])->all();
    }

    private static function configurationOptions(string $search, Get $get): array
    {
        $search = mb_strtolower(trim($search));

        return collect(self::matchingConfigurationOptions($get))
            ->filter(fn (string $label): bool => $search === '' || str_contains(mb_strtolower($label), $search))
            ->all();
    }

    private static function matchingConfigurationOptions(Get $get): array
    {
        $query = SalesConfiguration::query()->current()
            ->where('product_id', (int) $get('product_id'))
            ->where('active', true);
        if (filled($get('target_ram_mb'))) {
            $query->where('target_ram_mb', (int) $get('target_ram_mb'));
        }
        if (filled($get('target_storage_total_gb'))) {
            $query->where('target_storage_total_gb', $get('target_storage_total_gb'));
        }

        return $query->orderBy('display_name')->get(['id', 'display_name'])
            ->filter(fn (SalesConfiguration $configuration): bool => self::recipeOptions($configuration->id) !== [])
            ->mapWithKeys(fn (SalesConfiguration $configuration): array => [$configuration->id => $configuration->display_name])
            ->all();
    }

    private static function configurationTargetChanged(Get $get, Set $set): void
    {
        $set('sales_configuration_id', null);
        $set('upgrade_recipe_id', null);
        self::refreshConfigurationSelection($get, $set);
    }

    private static function refreshConfigurationSelection(Get $get, Set $set): void
    {
        $options = self::matchingConfigurationOptions($get);
        $current = filled($get('sales_configuration_id')) ? (int) $get('sales_configuration_id') : null;

        if (count($options) === 1) {
            $configurationId = (int) array_key_first($options);
            if ($current !== $configurationId) {
                $set('sales_configuration_id', $configurationId);
                self::configurationChanged($configurationId, $get, $set);
            }

            return;
        }

        if ($current !== null && ! array_key_exists($current, $options)) {
            $set('sales_configuration_id', null);
            $set('upgrade_recipe_id', null);
        }
    }

    private static function recipeOptions(int $configurationId): array
    {
        if ($configurationId < 1) {
            return [];
        }

        return UpgradeRecipe::query()->with(['salesConfiguration.product.hardwareProfile', 'lines.installComponent.product', 'lines.recoveredComponent.product'])
            ->where('sales_configuration_id', $configurationId)->where('active', true)
            ->orderByDesc('preferred')->orderBy('priority')->limit(30)->get()
            ->filter(fn (UpgradeRecipe $recipe): bool => app(UpgradeRecipeValidationService::class)->validate($recipe)->valid)
            ->mapWithKeys(fn (UpgradeRecipe $recipe): array => [$recipe->id => $recipe->name.($recipe->preferred ? ' · Preferred' : '')])->all();
    }

    private static function configurationChanged(mixed $state, Get $get, Set $set): void
    {
        $options = self::recipeOptions((int) $state);
        $set('upgrade_recipe_id', array_key_first($options));
        if (self::allowed(OrderPermission::EditSellingPrice)) {
            $suggested = self::suggestedPriceValue((int) $get('product_id'), (int) $state);
            if ($suggested !== null) {
                $set('selling_price', $suggested);
            }
        }
    }

    private static function recipeSummary(int $recipeId): string
    {
        $recipe = UpgradeRecipe::query()->with(['lines.installComponent', 'lines.recoveredComponent'])->find($recipeId);
        if ($recipe === null) {
            return 'Select a valid Build Method.';
        }

        return $recipe->lines->map(function ($line): string {
            $component = $line->installComponent?->specification ?? $line->recoveredComponent?->specification;

            return $line->operation->label().($component ? ": {$component}" : '').($line->target_slot_key ? " → {$line->target_slot_key}" : '');
        })->join('; ');
    }

    private static function configurationSummary(int $configurationId): string
    {
        $configuration = SalesConfiguration::query()->current()->whereKey($configurationId)->first([
            'id', 'display_name', 'target_ram_mb', 'target_storage_total_gb',
        ]);
        if ($configuration === null) {
            return 'Select a valid configuration.';
        }

        $targets = collect([
            $configuration->target_ram_mb === null ? null : 'RAM: '.self::capacityLabel($configuration->target_ram_mb, 'MB'),
            $configuration->target_storage_total_gb === null ? null : 'Storage: '.self::capacityLabel((float) $configuration->target_storage_total_gb, 'GB'),
        ])->filter()->implode(' · ');

        return $configuration->display_name.($targets === '' ? '' : " — {$targets}");
    }

    private static function suggestedPrice(int $productId, int $configurationId): string
    {
        $value = self::suggestedPriceValue($productId, $configurationId);

        return $value === null ? 'Select a valid configuration.' : AedMoney::format($value);
    }

    private static function suggestedPriceValue(int $productId, int $configurationId): ?string
    {
        $product = Product::query()->products()->find($productId, ['id', 'selling_price']);
        $configuration = SalesConfiguration::query()->current()->where('product_id', $productId)->whereKey($configurationId)->where('active', true)->first(['default_selling_price', 'suggested_selling_addon']);
        if ($product === null || $configuration === null) {
            return null;
        }
        if ($configuration->default_selling_price !== null) {
            return (string) $configuration->default_selling_price;
        }
        if ($product->selling_price === null) {
            return null;
        }

        return bcadd((string) $product->selling_price, (string) $configuration->suggested_selling_addon, 2);
    }

    private static function configurationWarning(Get $get): string
    {
        $productId = (int) $get('product_id');
        $product = Product::query()->products()
            ->with('hardwareProfile:id,product_id')
            ->find($productId, ['id']);
        if ($product?->hardwareProfile === null) {
            return 'Hardware profile incomplete.';
        }
        $count = count(self::matchingConfigurationOptions($get));
        if ($count === 0) {
            return 'No valid current configuration and build recipe match the selected targets.';
        }

        return "{$count} valid current configuration(s) match the selected targets. Component availability is validated when the Order is reserved.";
    }

    private static function capacityLabel(float|int $value, string $unit): string
    {
        if ($unit === 'MB' && $value >= 1024 && $value % 1024 === 0) {
            return ((int) $value / 1024).' GB';
        }

        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.').' '.$unit;
    }

    private static function platformChanged(mixed $state, Get $get, Set $set): void
    {
        $platformId = filled($state) ? (int) $state : null;
        $locations = app(OrderFulfillmentLocationService::class)->options($platformId);
        $currentLocation = (int) $get('warehouse_id');

        if (! array_key_exists($currentLocation, $locations)) {
            $set('warehouse_id', app(OrderFulfillmentLocationService::class)->defaultId());
        }

        self::refreshStockContexts($get, $set);
    }

    private static function refreshStockContexts(Get $get, Set $set): void
    {
        $items = $get('items');

        if (! is_array($items) || $items === []) {
            return;
        }

        $warehouseId = (int) $get('warehouse_id');
        $productIds = collect($items)->pluck('product_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values();
        $inventories = ProductInventory::query()
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $productIds)
            ->get(['product_id', 'available_quantity', 'reserved_quantity'])
            ->keyBy('product_id');

        foreach ($items as $key => $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $set(
                "items.{$key}.stock_context",
                $productId > 0 ? self::stockContextFromInventory($inventories->get($productId)) : null,
            );
        }
    }

    private static function stockContext(int $productId, int $warehouseId): string
    {
        $inventory = ProductInventory::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->first(['available_quantity', 'reserved_quantity']);

        return self::stockContextFromInventory($inventory);
    }

    private static function stockContextFromInventory(?ProductInventory $inventory): string
    {
        $available = $inventory?->available_quantity ?? 0;
        $reserved = $inventory?->reserved_quantity ?? 0;
        $sellable = $available - $reserved;
        $warning = $sellable <= 0 ? ' — Out of stock' : ($sellable <= 2 ? ' — Low stock' : '');

        return "Available: {$available} | Reserved: {$reserved} | Sellable: {$sellable}{$warning}";
    }

    private static function lineTotal(Get $get): string
    {
        $quantity = max(0, (int) $get('quantity'));
        $price = $get->string('selling_price', isNullable: true);

        return $price === null || ! function_exists('bcmul') ? 'AED 0.00' : AedMoney::format(bcmul((string) $quantity, $price, 2));
    }

    private static function allowed(OrderPermission $permission): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(OrderAuthorization::class)->allows($user, $permission);
    }
}
