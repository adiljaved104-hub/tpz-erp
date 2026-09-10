<?php

namespace App\Filament\Resources\Purchases\Schemas;

use App\DTOs\Purchases\PurchaseItemData;
use App\Enums\InventoryItemType;
use App\Enums\ProductMatchContext;
use App\Enums\PurchaseStatus;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ProductIntelligence\ProductSearchOptions;
use App\Services\Purchases\PurchaseFormLineService;
use App\Services\Purchases\PurchaseHandlerResolver;
use App\Services\Purchases\PurchasePriceVarianceService;
use App\Services\Purchases\PurchaseProductContextService;
use App\Services\Purchases\PurchaseTotalsCalculator;
use App\Support\AedMoney;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Throwable;

class PurchaseForm
{
    public static function configure(Schema $schema): Schema
    {
        $purchaseSection = Section::make('Purchase')->schema([
            Select::make('warehouse_id')->label('Warehouse / Inventory Location')->options(fn (): array => Warehouse::query()->active()->orderByDesc('is_default')->orderBy('name')->pluck('name', 'id')->all())
                ->default(fn (): ?int => Warehouse::query()->active()->where('is_default', true)->value('id'))
                ->searchable()->required()->live()
                ->afterStateUpdated(function ($state, Get $get, Set $set, ?Purchase $record): void {
                    $user = auth()->user();

                    if (! $state || ! $user instanceof User) {
                        return;
                    }

                    $set('items', app(PurchaseFormLineService::class)->enrich(
                        $get('items') ?? [],
                        (int) $state,
                        $user,
                        self::contextPurchase($record, $user),
                    ));
                    self::syncHandler($get('items') ?? [], (int) $state, $get, $set, $record);
                }),
            DatePicker::make('purchase_date')->default(now())->required(),
            Hidden::make('currency')->default('AED'),
            Select::make('handled_by_employee_id')
                ->label('Handled By')
                ->options(fn (?Purchase $record): array => app(PurchaseHandlerResolver::class)->activeEmployeeOptions($record?->handled_by_employee_id))
                ->searchable()
                ->preload()
                ->disabled(fn (): bool => ! self::mayChooseHandler())
                ->dehydrated(fn (): bool => self::mayChooseHandler())
                ->helperText(fn (Get $get): string => self::handlerHelper($get)),
            Hidden::make('handler_resolution_employee_id')->dehydrated(false),
        ])->columns(2)->compact();

        $purchaseItemsSection = Section::make('Purchase Items')
            ->key('purchase-items')
            ->headerActions([
                Action::make('bulkAddProducts')
                    ->label('Bulk Add Products')
                    ->modalHeading('Add several Products')
                    ->disabled(fn (Get $get): bool => blank($get('warehouse_id')))
                    ->tooltip(fn (Get $get): ?string => blank($get('warehouse_id')) ? 'Select a Warehouse / Inventory Location first.' : null)
                    ->schema([
                        Select::make('product_ids')
                            ->label('Products')
                            ->multiple()
                            ->searchable()
                            ->options([])
                            ->getSearchResultsUsing(fn (string $search, $livewire): array => self::productOptions($search, (int) ($livewire->data['warehouse_id'] ?? 0)))
                            ->getOptionLabelsUsing(fn (array $values): array => self::productLabels($values))
                            ->searchPrompt('Type at least 2 characters to search Products.')
                            ->noSearchResultsMessage('No active Products match your search.')
                            ->required(),
                    ])
                    ->action(function (array $data, Get $get, Set $set, ?Purchase $record): void {
                        $user = auth()->user();

                        if (! $user instanceof User) {
                            abort(403);
                        }

                        $lines = app(PurchaseFormLineService::class)->addProducts(
                            $get('items') ?? [],
                            $data['product_ids'],
                            (int) $get('warehouse_id'),
                            $user,
                            self::contextPurchase($record, $user),
                        );
                        $set('items', $lines);
                        self::syncHandler($lines, (int) $get('warehouse_id'), $get, $set, $record);
                    }),
            ])
            ->schema([
                Repeater::make('items')
                    ->schema([
                        self::productSelect(),
                        TextInput::make('ordered_quantity')
                            ->label('Quantity')
                            ->numeric()->integer()->minValue(1)->default(1)->required()->live(onBlur: true),
                        TextInput::make('unit_cost')
                            ->label('Unit Cost')->prefix('AED')
                            ->rule('regex:/^\d{1,11}(?:\.\d{1,4})?$/')->required()->live(onBlur: true)
                            ->helperText(fn (Get $get): ?string => self::costHelper($get))
                            ->afterStateUpdated(fn (Set $set): mixed => $set('unit_cost_touched', true)),
                        Placeholder::make('line_total')
                            ->label('Line Total')
                            ->content(fn (Get $get): string => self::lineTotal($get)),
                        Hidden::make('unit_cost_touched')->default(false)->dehydrated(false),
                        Hidden::make('unit_cost_suggested')->default(false)->dehydrated(false),
                        Hidden::make('line_discount_total')->default('0.00'),
                        Hidden::make('vat_rate')->default('0.00'),
                        Hidden::make('vat_amount')->default('0.00')->dehydrated(false),
                        Hidden::make('notes'),
                        Hidden::make('stock_context')->dehydrated(false),
                        Hidden::make('latest_received_cost')->dehydrated(false),
                    ])
                    ->table([
                        TableColumn::make('Product')->markAsRequired()->width('46%'),
                        TableColumn::make('Quantity')->markAsRequired()->width('14%'),
                        TableColumn::make('Unit Cost')->markAsRequired()->width('20%'),
                        TableColumn::make('Line Total')->width('20%'),
                    ])
                    ->compact()
                    ->reorderable(false)
                    ->minItems(1)
                    ->defaultItems(1)
                    ->addActionLabel('Add Product')
                    ->columnSpanFull(),
                Grid::make(4)->schema([
                    Placeholder::make('calculated_subtotal')->label('Subtotal')->content(fn (Get $get): string => self::formTotal($get, 'subtotal')),
                    Placeholder::make('calculated_discount')->label('Discount')->content(fn (Get $get): string => self::formTotal($get, 'discount_total')),
                    Placeholder::make('calculated_net')->label('Net before VAT')->content(fn (Get $get): string => self::formTotal($get, 'net_before_vat')),
                    Placeholder::make('calculated_grand_total')->label('Grand Total')->content(fn (Get $get): string => self::formTotal($get, 'grand_total')),
                ]),
            ]);

        $optionalDetailsSection = Section::make('Optional Purchase Details')
            ->schema([
                Select::make('supplier_id')
                    ->label('Supplier')
                    ->placeholder('No Supplier')
                    ->options(fn (): array => Supplier::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                TextInput::make('supplier_invoice_number')->label('Supplier Invoice Number')->maxLength(255),
                DatePicker::make('supplier_invoice_date')->label('Supplier Invoice Date'),
                DatePicker::make('expected_delivery_date')->label('Expected Delivery Date'),
                TextInput::make('external_accounting_reference')->label('External Accounting Reference')->maxLength(255),
                TextInput::make('shipping_total')->label('Shipping')->prefix('AED')->default('0.00')->required()->live(onBlur: true),
                TextInput::make('other_charges_total')->label('Other Charges')->prefix('AED')->default('0.00')->required()->live(onBlur: true),
                Hidden::make('shipping_vat_rate')->default('0.00'),
                Hidden::make('other_charges_vat_rate')->default('0.00'),
                Textarea::make('notes')->maxLength(5000)->columnSpanFull(),
            ])
            ->columns(2)
            ->collapsed()
            ->collapsible();

        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 2])
                ->schema([
                    Grid::make(1)
                        ->schema([
                            $purchaseSection,
                            $optionalDetailsSection,
                        ])
                        ->dense(),
                    Grid::make(1)
                        ->schema([
                            $purchaseItemsSection,
                        ]),
                ])
                ->columnSpanFull(),
        ]);
    }

    private static function productSelect(): Select
    {
        return Select::make('product_id')
            ->label('Product / Component')
            ->placeholder('Search by SKU, product name, or component specification')
            ->searchable()
            ->options([])
            ->getSearchResultsUsing(fn (string $search, Get $get): array => self::productOptions($search, (int) $get('../../warehouse_id')))
            ->getOptionLabelUsing(fn ($value): ?string => self::productLabels([(int) $value])[(int) $value] ?? null)
            ->searchPrompt('Type at least 2 characters to search Products or Components.')
            ->noSearchResultsMessage('No active Products or Components match your search.')
            ->disableOptionsWhenSelectedInSiblingRepeaterItems()
            ->disabled(fn (Get $get): bool => ! self::hasSelectedWarehouse($get))
            ->required()
            ->live()
            ->helperText(fn (Get $get): ?string => self::hasSelectedWarehouse($get)
                ? $get->string('stock_context', isNullable: true)
                : 'Select a Warehouse / Inventory Location first.')
            ->afterStateUpdated(function ($state, Get $get, Set $set, ?Purchase $record): void {
                $set('stock_context', null);
                $set('latest_received_cost', null);

                if (! $state) {
                    self::syncHandler($get('../../items') ?? [], (int) $get('../../warehouse_id'), $get, $set, $record, '../../');

                    return;
                }

                $warehouseId = (int) $get('../../warehouse_id');
                $user = auth()->user();

                if (! $warehouseId || ! $user instanceof User) {
                    $set('stock_context', 'Select a Warehouse / Inventory Location to load stock context.');

                    return;
                }

                $context = app(PurchaseProductContextService::class)->forProducts(
                    $user,
                    $warehouseId,
                    [(int) $state],
                    self::contextPurchase($record, $user),
                )[(int) $state] ?? null;
                $contextState = app(PurchaseFormLineService::class)->contextState($context);
                $set('stock_context', $contextState['stock_context']);
                $set('latest_received_cost', $contextState['latest_received_cost']);

                if (! $get->boolean('unit_cost_touched') && blank($get->string('unit_cost', isNullable: true)) && $context?->latestReceivedCost !== null) {
                    $set('unit_cost', $context->latestReceivedCost);
                    $set('unit_cost_suggested', true);
                }

                self::syncHandler($get('../../items') ?? [], $warehouseId, $get, $set, $record, '../../');
            });
    }

    /** @return array<int, string> */
    private static function productOptions(string $search, int $warehouseId): array
    {
        if (mb_strlen(trim($search)) < 2) {
            return [];
        }

        $platformId = $warehouseId > 0
            ? Warehouse::query()->whereKey($warehouseId)->value('marketplace_platform_id')
            : null;

        return app(ProductSearchOptions::class)->search(
            $search,
            ProductMatchContext::Purchase,
            auth()->user(),
            $warehouseId > 0 ? $warehouseId : null,
            $platformId === null ? null : (int) $platformId,
        );
    }

    /** @param array<int, int|string> $ids
     * @return array<int, string>
     */
    private static function productLabels(array $ids): array
    {
        return Product::query()->whereKey($ids)->get(['id', 'sku', 'inventory_item_type', 'name', 'brand', 'model'])
            ->mapWithKeys(fn (Product $product): array => [$product->id => self::productLabel($product)])
            ->all();
    }

    private static function productLabel(Product $product): string
    {
        $details = collect([$product->brand, $product->model])->filter()->implode(' ');

        $kind = $product->inventory_item_type === InventoryItemType::Component ? '[Component] ' : '';

        return "{$kind}{$product->sku} — {$product->name}".($details === '' ? '' : " ({$details})");
    }

    private static function hasSelectedWarehouse(Get $get): bool
    {
        return (int) $get('../../warehouse_id') > 0;
    }

    private static function costHelper(Get $get): ?string
    {
        $latest = $get->string('latest_received_cost', isNullable: true);
        $entered = $get->string('unit_cost', isNullable: true);
        $advisory = app(PurchasePriceVarianceService::class)->advisory($latest, $entered);

        if ($advisory !== null) {
            return $advisory;
        }

        return $get->boolean('unit_cost_suggested') && $latest !== null
            ? 'Suggested from latest received cost: '.AedMoney::format($latest)
            : null;
    }

    private static function lineTotal(Get $get): string
    {
        if (blank($get->string('unit_cost', isNullable: true))) {
            return 'AED 0.00';
        }

        try {
            $line = app(PurchaseTotalsCalculator::class)->line(new PurchaseItemData(
                productId: (int) $get('product_id'),
                orderedQuantity: (int) ($get('ordered_quantity') ?: 1),
                unitCost: $get->string('unit_cost'),
                lineDiscountTotal: $get->string('line_discount_total') ?: '0.00',
                vatRate: $get->string('vat_rate') ?: '0.00',
            ));

            return AedMoney::format($line['line_total']);
        } catch (Throwable) {
            return 'AED 0.00';
        }
    }

    private static function formTotal(Get $get, string $field): string
    {
        try {
            $calculator = app(PurchaseTotalsCalculator::class);
            $lines = collect($get('items') ?? [])->filter(fn (array $line): bool => filled($line['unit_cost'] ?? null))
                ->map(fn (array $line): array => $calculator->line(new PurchaseItemData(
                    productId: (int) ($line['product_id'] ?? 0),
                    orderedQuantity: (int) ($line['ordered_quantity'] ?? 1),
                    unitCost: (string) $line['unit_cost'],
                    lineDiscountTotal: (string) ($line['line_discount_total'] ?? '0.00'),
                    vatRate: (string) ($line['vat_rate'] ?? '0.00'),
                )))->all();
            $totals = $calculator->document(
                $lines,
                $get->string('shipping_total') ?: '0.00',
                '0.00',
                $get->string('other_charges_total') ?: '0.00',
                '0.00',
            );

            return AedMoney::format($totals[$field]);
        } catch (Throwable) {
            return 'AED 0.00';
        }
    }

    private static function contextPurchase(?Purchase $record, User $user): Purchase
    {
        return $record ?? (new Purchase)->forceFill(['status' => PurchaseStatus::Draft, 'created_by_user_id' => $user->id]);
    }

    /** @param array<int|string, array<string, mixed>> $items */
    private static function syncHandler(array $items, int $warehouseId, Get $get, Set $set, ?Purchase $record, string $prefix = ''): void
    {
        if ($warehouseId < 1 || ($record?->handled_by_employee_id !== null && blank($get("{$prefix}handler_resolution_employee_id")))) {
            return;
        }

        $productIds = collect($items)->pluck('product_id')->filter()->map(fn ($id): int => (int) $id)->all();
        $resolution = app(PurchaseHandlerResolver::class)->resolve($productIds, $warehouseId);
        $current = $get("{$prefix}handled_by_employee_id");
        $previousAuto = $get("{$prefix}handler_resolution_employee_id");

        if (blank($current) || ($previousAuto !== null && (int) $current === (int) $previousAuto)) {
            $set("{$prefix}handled_by_employee_id", $resolution['employee_id']);
        }

        $set("{$prefix}handler_resolution_employee_id", $resolution['employee_id']);
    }

    private static function handlerHelper(Get $get): string
    {
        $warehouseId = (int) $get('warehouse_id');
        $productIds = collect($get('items') ?? [])->pluck('product_id')->filter()->map(fn ($id): int => (int) $id)->all();

        if ($warehouseId < 1 || $productIds === []) {
            return 'Add Purchase products to resolve the operational handler from active Responsibilities.';
        }

        $resolution = app(PurchaseHandlerResolver::class)->resolve($productIds, $warehouseId);

        return match ($resolution['status']) {
            'matched' => 'Automatically matched from active Product/Brand/Category Responsibility.',
            'ambiguous' => self::mayChooseHandler()
                ? 'Multiple responsible employees match these lines. Select the active employee who will handle this Purchase.'
                : 'Multiple responsible employees match these lines; no handler will be selected automatically.',
            default => self::mayChooseHandler()
                ? 'No matching Product/Brand/Category Responsibility. Select an active handler if needed.'
                : 'No matching Product/Brand/Category Responsibility; no handler will be selected automatically.',
        };
    }

    private static function mayChooseHandler(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(PurchaseHandlerResolver::class)->mayChoose($user);
    }
}
