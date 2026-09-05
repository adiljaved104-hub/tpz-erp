<?php

namespace App\Filament\Pages\Purchasing;

use App\Actions\Purchases\QuickStockPurchase as QuickStockPurchaseAction;
use App\DTOs\Purchases\PurchaseItemData;
use App\DTOs\Purchases\QuickStockPurchaseData;
use App\Enums\InventoryItemType;
use App\Enums\ProductMatchContext;
use App\Enums\PurchasePermission;
use App\Filament\Resources\PurchaseReceipts\PurchaseReceiptResource;
use App\Models\Employee;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\ProductIntelligence\ProductSearchOptions;
use App\Services\Purchases\PurchaseCostHistoryService;
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
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class QuickStockPurchase extends Page
{
    protected static ?string $slug = 'quick-stock-purchase';

    protected static ?string $navigationLabel = 'Quick Stock Purchase';

    protected static string|\UnitEnum|null $navigationGroup = 'Purchasing';

    protected static ?int $navigationSort = 2;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(PurchaseAuthorization::class)->allows($user, PurchasePermission::QuickReceive);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $employee = auth()->user()?->employee;
        $this->getSchema('content')->fill([
            'idempotency_key' => (string) Str::uuid(),
            'purchase_date' => now()->toDateString(),
            'handled_by_employee_id' => $employee?->status ? $employee->id : null,
            'shipping_total' => '0.00',
            'other_charges_total' => '0.00',
            'items' => [['ordered_quantity' => 1, 'unit_cost_touched' => false]],
        ]);
    }

    public function hydrate(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Quick Stock Purchase')->schema([
                Grid::make(['default' => 1, 'lg' => 3])->schema([
                    DatePicker::make('purchase_date')->required()->default(now()),
                    Select::make('warehouse_id')->label('Warehouse')->required()->searchable()->live()
                        ->options(fn (): array => Warehouse::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                        ->afterStateUpdated(function ($state, Set $set, QuickStockPurchase $livewire): void {
                            $set('items', self::refreshLineContexts(
                                (int) $state,
                                $livewire->data['items'] ?? [],
                            ));
                        }),
                    Select::make('handled_by_employee_id')->label('Handled By / Reported By')->searchable()->nullable()
                        ->options(fn (): array => Employee::query()->where('status', true)->orderBy('name')->pluck('name', 'id')->all()),
                ]),
                Textarea::make('notes')->maxLength(5000)->rows(2),
            ])->compact(),
            Section::make('Product Lines')->headerActions([
                Action::make('bulkAddProducts')->label('Bulk Add Products')->disabled(fn (Get $get): bool => blank($get('warehouse_id')))
                    ->schema([
                        Select::make('product_ids')->multiple()->searchable()->required()
                            ->options([])
                            ->getSearchResultsUsing(fn (string $search): array => self::productOptions($search))
                            ->getOptionLabelsUsing(fn (array $values): array => self::productLabels(array_map('intval', $values)))
                            ->searchPrompt('Type at least 2 characters to search Products.'),
                    ])->action(function (array $data, Get $get, Set $set): void {
                        $lines = $get('items') ?? [];
                        $present = collect($lines)->pluck('product_id')->filter()->map(fn ($id): int => (int) $id);
                        $ids = collect($data['product_ids'])->map(fn ($id): int => (int) $id)->unique()->reject(fn ($id): bool => $present->contains($id));
                        $contexts = self::contexts((int) $get('warehouse_id'), $ids->all());

                        foreach ($ids as $id) {
                            $context = $contexts[$id] ?? null;
                            $lines[(string) Str::uuid()] = self::newLine($id, $context);
                        }

                        $set('items', $lines);
                    }),
            ])->schema([
                Repeater::make('items')->schema([
                    Select::make('product_id')->label('Product / Component')
                        ->placeholder('Search by SKU, product name, or component specification')
                        ->searchable()->required()->live()
                        ->options([])
                        ->getSearchResultsUsing(fn (string $search): array => self::productOptions($search))
                        ->getOptionLabelUsing(fn ($value): ?string => self::productLabels([(int) $value])[(int) $value] ?? null)
                        ->searchPrompt('Type at least 2 characters to search Products or Components.')
                        ->noSearchResultsMessage('No active Products or Components match your search.')
                        ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                        ->disabled(fn (QuickStockPurchase $livewire): bool => ! self::warehouseSelected($livewire))
                        ->helperText(fn (QuickStockPurchase $livewire): ?string => self::warehouseSelected($livewire) ? null : 'Select a Warehouse first.')
                        ->afterStateUpdated(function ($state, Get $get, Set $set, QuickStockPurchase $livewire): void {
                            $set('stock_context', null);
                            $set('latest_received_cost', null);

                            $productId = (int) $state;
                            $warehouseId = (int) ($livewire->data['warehouse_id'] ?? 0);

                            if ($productId < 1 || $warehouseId < 1) {
                                return;
                            }

                            $context = self::contexts($warehouseId, [$productId])[$productId] ?? null;
                            $latestCost = self::normalizeLatestCost($context?->latestReceivedCost);
                            $set('stock_context', $context === null ? null : "Avail {$context->availableQuantity}; Res {$context->reservedQuantity}; Sellable {$context->sellableQuantity()}; Damaged {$context->damagedQuantity}; On hand {$context->totalOnHand()}");
                            $set('latest_received_cost', $latestCost);

                            if (! $get->boolean('unit_cost_touched') && blank($get->string('unit_cost', isNullable: true)) && $latestCost !== null) {
                                $set('unit_cost', $latestCost);
                                $set('unit_cost_suggested', true);
                            }
                        }),
                    TextInput::make('ordered_quantity')->label('Quantity')->integer()->minValue(1)->default(1)->required()->live(onBlur: true),
                    TextInput::make('unit_cost')->label('Unit Cost')->prefix('AED')->required()->rule('regex:/^\d{1,11}(?:\.\d{1,4})?$/')->live(onBlur: true)
                        ->helperText(fn (Get $get): ?string => self::costAdvisory($get))
                        ->afterStateUpdated(fn (Set $set): mixed => $set('unit_cost_touched', true)),
                    Placeholder::make('latest_received_cost_display')->label('Latest Purchase Cost')
                        ->content(fn (Get $get): string => ($cost = $get->string('latest_received_cost', isNullable: true)) === null
                            ? 'No received purchase cost'
                            : AedMoney::format($cost))
                        ->suffixAction(self::historyAction()),
                    Placeholder::make('stock_context_display')->label('Current Stock')->content(fn (Get $get): string => $get->string('stock_context', isNullable: true) ?? 'Select a Product'),
                    Placeholder::make('line_total')->label('Line Total')->content(fn (Get $get): string => self::lineTotal($get)),
                    Hidden::make('latest_received_cost')->dehydrated(false),
                    Hidden::make('stock_context')->dehydrated(false),
                    Hidden::make('unit_cost_touched')->default(false)->dehydrated(false),
                    Hidden::make('unit_cost_suggested')->default(false)->dehydrated(false),
                ])->table([
                    TableColumn::make('Product')->markAsRequired()->width('30%'),
                    TableColumn::make('Quantity')->markAsRequired()->width('9%'),
                    TableColumn::make('Unit Cost')->markAsRequired()->width('14%'),
                    TableColumn::make('Latest Purchase Cost')->width('14%'),
                    TableColumn::make('Current Stock')->width('22%'),
                    TableColumn::make('Line Total')->width('11%'),
                ])->compact()->reorderable(false)->minItems(1)->defaultItems(1)->addActionLabel('Add Product')->columnSpanFull(),
                Placeholder::make('summary')->label('Posting Summary')
                    ->content(fn (QuickStockPurchase $livewire): string => self::summary($livewire->data['items'] ?? [])),
            ]),
            Section::make('Optional Purchase Details')->collapsed()->collapsible()->columns(2)->schema([
                Select::make('supplier_id')->label('Supplier')->searchable()->placeholder('No Supplier')
                    ->options(fn (): array => Supplier::query()->active()->orderBy('name')->pluck('name', 'id')->all()),
                TextInput::make('supplier_invoice_number')->maxLength(255),
                DatePicker::make('supplier_invoice_date'),
                TextInput::make('supplier_delivery_note')->maxLength(255),
                TextInput::make('external_accounting_reference')->maxLength(255),
                TextInput::make('shipping_total')->prefix('AED')->default('0.00')->required(),
                TextInput::make('other_charges_total')->prefix('AED')->default('0.00')->required(),
                Hidden::make('idempotency_key')->required(),
            ]),
            Actions::make([$this->postAction()])->alignEnd(),
        ]);
    }

    public function postAction(): Action
    {
        return Action::make('post')->label('Post Quick Stock Purchase')->color('primary')->requiresConfirmation()
            ->modalHeading('Receive stock immediately?')
            ->modalDescription(fn (): string => self::summary($this->data['items'] ?? []).'. This will immediately receive the listed stock into Inventory and update average costs.')
            ->action(fn () => $this->post());
    }

    public function post(): void
    {
        abort_unless(static::canAccess(), 403);
        $state = $this->getSchema('content')->getState();

        try {
            $result = app(QuickStockPurchaseAction::class)->handle(new QuickStockPurchaseData(
                warehouseId: (int) $state['warehouse_id'], purchaseDate: (string) $state['purchase_date'],
                items: collect($state['items'])->map(fn (array $line): PurchaseItemData => new PurchaseItemData(
                    (int) $line['product_id'], (int) $line['ordered_quantity'], (string) $line['unit_cost'], '0.00', '0.00',
                ))->values()->all(),
                idempotencyKey: (string) $state['idempotency_key'],
                supplierId: filled($state['supplier_id'] ?? null) ? (int) $state['supplier_id'] : null,
                handledByEmployeeId: filled($state['handled_by_employee_id'] ?? null) ? (int) $state['handled_by_employee_id'] : null,
                supplierInvoiceNumber: $state['supplier_invoice_number'] ?? null,
                supplierInvoiceDate: $state['supplier_invoice_date'] ?? null,
                supplierDeliveryNote: $state['supplier_delivery_note'] ?? null,
                externalAccountingReference: $state['external_accounting_reference'] ?? null,
                shippingTotal: (string) ($state['shipping_total'] ?? '0.00'), otherChargesTotal: (string) ($state['other_charges_total'] ?? '0.00'),
                notes: $state['notes'] ?? null,
            ), auth()->user());
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->danger()->title('Quick Stock Purchase was not posted')->body($exception->getMessage())->send();

            return;
        }

        Notification::make()->success()->title($result->replayed ? 'Existing Quick Stock Purchase opened' : 'Stock received successfully')->send();
        $this->redirect(PurchaseReceiptResource::getUrl('view', ['record' => $result->receipt]), navigate: true);
    }

    /** @return array<int, string> */
    private static function productOptions(string $search): array
    {
        if (mb_strlen(trim($search)) < 2) {
            return [];
        }

        return app(ProductSearchOptions::class)->search(
            $search,
            ProductMatchContext::Receiving,
            auth()->user(),
        );
    }

    /** @param array<int, int> $ids */
    private static function productLabels(array $ids): array
    {
        return Product::query()->whereKey($ids)->get(['id', 'sku', 'inventory_item_type', 'name', 'brand', 'model'])
            ->mapWithKeys(fn (Product $product): array => [$product->id => self::productLabel($product)])->all();
    }

    private static function productLabel(Product $product): string
    {
        $details = collect([$product->brand, $product->model])->filter()->implode(' ');

        $kind = $product->inventory_item_type === InventoryItemType::Component ? '[Component] ' : '';

        return "{$kind}{$product->sku} — {$product->name}".($details === '' ? '' : " ({$details})");
    }

    private static function warehouseSelected(QuickStockPurchase $livewire): bool
    {
        return (int) ($livewire->data['warehouse_id'] ?? 0) > 0;
    }

    /** @param array<int, int> $ids */
    private static function contexts(int $warehouseId, array $ids): array
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return app(PurchaseProductContextService::class)->forQuickStockPurchase($user, $warehouseId, $ids);
    }

    private static function newLine(int $id, $context): array
    {
        $latestCost = self::normalizeLatestCost($context?->latestReceivedCost);

        return [
            'product_id' => $id, 'ordered_quantity' => 1, 'unit_cost' => $latestCost,
            'unit_cost_touched' => false, 'unit_cost_suggested' => $latestCost !== null,
            'latest_received_cost' => $latestCost,
            'stock_context' => $context === null ? null : "Avail {$context->availableQuantity}; Res {$context->reservedQuantity}; Sellable {$context->sellableQuantity()}; Damaged {$context->damagedQuantity}; On hand {$context->totalOnHand()}",
        ];
    }

    /**
     * @param  array<string|int, array<string, mixed>>  $lines
     * @return array<string|int, array<string, mixed>>
     */
    private static function refreshLineContexts(int $warehouseId, array $lines): array
    {
        $productIds = collect($lines)->pluck('product_id')->filter()
            ->map(fn ($id): int => (int) $id)->unique()->values();
        $contexts = $warehouseId > 0 && $productIds->isNotEmpty()
            ? self::contexts($warehouseId, $productIds->all())
            : [];

        foreach ($lines as $key => $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $context = $contexts[$productId] ?? null;
            $latestCost = self::normalizeLatestCost($context?->latestReceivedCost);
            $manualCost = filled($line['unit_cost'] ?? null) || (bool) ($line['unit_cost_touched'] ?? false);
            $lines[$key] = [
                ...$line,
                'ordered_quantity' => (int) ($line['ordered_quantity'] ?? 1),
                'latest_received_cost' => $latestCost,
                'stock_context' => $context === null ? null : "Avail {$context->availableQuantity}; Res {$context->reservedQuantity}; Sellable {$context->sellableQuantity()}; Damaged {$context->damagedQuantity}; On hand {$context->totalOnHand()}",
            ];

            if (! $manualCost && $latestCost !== null) {
                $lines[$key]['unit_cost'] = $latestCost;
                $lines[$key]['unit_cost_suggested'] = true;
            }
        }

        return $lines;
    }

    private static function normalizeLatestCost(?string $cost): ?string
    {
        return $cost === null ? null : bcadd($cost, '0', 4);
    }

    private static function historyAction(): Action
    {
        return Action::make('purchaseCostHistory')->icon('heroicon-o-clock')->tooltip('View received Purchase cost history')
            ->visible(fn (Get $get): bool => (int) $get('product_id') > 0)->authorize(function (): bool {
                $user = auth()->user();

                return $user instanceof User && app(PurchaseAuthorization::class)->allows($user, PurchasePermission::ViewCostHistory);
            })->slideOver()->modalHeading('Purchase Cost History')->modalSubmitAction(false)->modalCancelActionLabel('Close')
            ->modalContent(function (Get $get): View {
                $user = auth()->user();
                abort_unless($user instanceof User, 403);

                return view('filament.purchases.purchase-cost-history', [
                    'history' => app(PurchaseCostHistoryService::class)->summaryForProduct((int) $get('product_id'), $user),
                ]);
            });
    }

    private static function costAdvisory(Get $get): ?string
    {
        return app(PurchasePriceVarianceService::class)->advisory(
            $get->string('latest_received_cost', isNullable: true),
            $get->string('unit_cost', isNullable: true),
        );
    }

    private static function lineTotal(Get $get): string
    {
        if (blank($get->string('unit_cost', isNullable: true))) {
            return 'AED 0.00';
        }

        try {
            $line = app(PurchaseTotalsCalculator::class)->line(new PurchaseItemData(
                (int) $get('product_id'), (int) ($get('ordered_quantity') ?: 1), $get->string('unit_cost'),
            ));

            return AedMoney::format($line['line_total']);
        } catch (Throwable) {
            return 'AED 0.00';
        }
    }

    /** @param array<string|int, array<string, mixed>> $lines */
    private static function summary(array $lines): string
    {
        $selected = collect($lines)->filter(fn (array $line): bool => (int) ($line['product_id'] ?? 0) > 0);
        $productCount = $selected->count();
        $quantity = $selected->sum(fn (array $line): int => (int) ($line['ordered_quantity'] ?? 0));

        return $productCount.' '.Str::plural('Product', $productCount).'; '
            .$quantity.' total '.Str::plural('unit', $quantity);
    }
}
