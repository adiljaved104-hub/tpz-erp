<?php

namespace App\Filament\Pages;

use App\Actions\Orders\ReserveDraftOrder;
use App\Actions\Orders\SaveAndReserveOrder;
use App\Actions\Orders\SaveAsShippedOrder;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\Enums\OrderPermission;
use App\Enums\OrderStatus;
use App\Enums\QcPermission;
use App\Enums\QuotationPermission;
use App\Enums\WebSalesChannel;
use App\Enums\WebSalesPermission;
use App\Exceptions\InvalidOrderTransitionException;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Schemas\OrderForm;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\TaxInvoices\TaxInvoiceResource;
use App\Filament\Resources\WebSalesOrders\Pages\CreateWebSalesOrder;
use App\Filament\Resources\WebSalesOrders\WebSalesOrderResource;
use App\Models\Order;
use App\Models\TaxInvoice;
use App\Models\User;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\QcAuthorization;
use App\Services\Authorization\QuotationAuthorization;
use App\Services\Authorization\WebSalesAuthorization;
use App\Services\Dashboard\ErpDashboardService;
use App\Services\Invoices\TaxInvoiceDocumentService;
use App\Services\Invoices\TaxInvoiceService;
use App\Services\Navigation\NavigationPreferenceService;
use App\Services\Navigation\WorkspaceRecordPreviewService;
use App\Services\Orders\OrderAmendmentService;
use App\Services\Orders\OrderFulfillmentLocationService;
use App\Services\Orders\OrderReadService;
use App\Services\Orders\OrderService;
use App\Services\Orders\WebSalesReadService;
use App\Services\Orders\WebSalesService;
use App\Services\Qc\RenewedQcDispatchService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Livewire\WithPagination;

class SalesWorkspace extends Page
{
    use WithPagination;

    protected string $view = 'filament.pages.sales-workspace';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Sales';

    protected static ?string $slug = 'sales-workspace';

    protected static ?int $navigationSort = 0;

    public string $activeTab = 'overview';

    public ?int $activeExtraModule = null;

    public string $status = 'all';

    public string $search = '';

    public string $customer = '';

    public string $channel = '';

    public string $from = '';

    public string $to = '';

    // Only an ID and a module type live in Livewire state. Every render
    // reloads the requested record through its existing responsibility-scoped
    // read service, so stale or tampered IDs cannot disclose data.
    public ?int $quickViewOrderId = null;

    public ?string $quickViewOrderType = null;

    public bool $confirmQuickViewReserve = false;

    public ?string $pendingWebSaleOperation = null;

    /**
     * Reuses the exact full Sales Order form fields, product search and
     * existing atomic SaveAndReserveOrder action; no second reservation path.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('createWorkspaceWebSale')
                ->label('Create Web Sale')
                ->modalHeading('Create Web Sale')
                ->modalWidth('4xl')
                ->modalSubmitActionLabel('Save & Confirm')
                ->model(Order::class)
                ->visible(fn (): bool => static::canAccess() && WebSalesOrderResource::canCreate())
                ->schema(fn (Schema $schema): Schema => WebSalesOrderResource::form($schema))
                ->action(function (array $data): void {
                    abort_unless(static::canAccess() && WebSalesOrderResource::canCreate(), 403);
                    try {
                        $order = app(WebSalesService::class)->createConfirmed(
                            CreateWebSalesOrder::makeOrderData($data),
                            auth()->user(),
                        );
                    } catch (ValidationException $exception) {
                        throw $exception;
                    } catch (\Throwable $exception) {
                        report($exception);
                        Notification::make()->danger()->title('Web Sale could not be saved')
                            ->body('Please review the form and try again.')->send();

                        throw new Halt;
                    }

                    $this->activeTab = 'webSales';
                    $this->resetPage('web-sales-page');
                    Notification::make()->success()->title('Web Sale confirmed')
                        ->body('Order '.$order->reference.' created.')->send();
                }),
            Action::make('createWorkspaceTaxInvoice')
                ->label('Create Tax Invoice')
                ->modalHeading('Create Tax Invoice')
                ->modalDescription('Enter VAT-inclusive prices. VAT and totals are calculated automatically; the invoice number is assigned when saved.')
                ->modalWidth('6xl')
                ->extraModalWindowAttributes(['class' => 'tpz-tax-invoice-modal'])
                ->modalSubmitAction(false)
                ->extraModalFooterActions(fn (Action $action): array => [
                    $action->makeModalSubmitAction('saveDownloadAndNew', arguments: ['downloadAndNew' => true])
                        ->label('Save, Download & New')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->color('primary'),
                    $action->makeModalSubmitAction('saveInvoice', arguments: ['downloadAndNew' => false])
                        ->label('Save Invoice')
                        ->icon('heroicon-o-check')
                        ->color('gray'),
                ])
                ->model(TaxInvoice::class)
                ->visible(fn (): bool => static::canAccess() && TaxInvoiceResource::canCreate())
                ->schema(fn (Schema $schema): Schema => TaxInvoiceResource::workspaceForm($schema))
                ->action(function (array $data, array $arguments): ?StreamedResponse {
                    abort_unless(static::canAccess() && TaxInvoiceResource::canCreate(), 403);

                    try {
                        $invoice = app(TaxInvoiceService::class)->create($data, auth()->user());
                    } catch (ValidationException $exception) {
                        throw $exception;
                    } catch (\Throwable $exception) {
                        report($exception);
                        Notification::make()->danger()->title('Invoice could not be created')
                            ->body('Please review the form and try again.')->send();

                        throw new Halt;
                    }

                    if (($arguments['downloadAndNew'] ?? false) === false) {
                        Notification::make()->success()->title('Tax Invoice created')
                            ->body("Invoice {$invoice->invoice_number} saved.")->send();

                        return null;
                    }

                    try {
                        $documents = app(TaxInvoiceDocumentService::class);
                        $pdfContent = $documents->pdf($invoice)->output();
                        $filename = $documents->filename($invoice);
                    } catch (\Throwable $exception) {
                        report($exception);
                        Notification::make()->warning()->title('Invoice saved, but PDF download failed')
                            ->body("{$invoice->invoice_number} was created. You can download its PDF from Tax Invoices.")->send();
                        $this->replaceMountedAction('createWorkspaceTaxInvoice');

                        return null;
                    }

                    Notification::make()->success()->title('Invoice created')
                        ->body("{$invoice->invoice_number} saved. Opening a new Invoice form.")->send();
                    // Re-mounting creates a fresh form and idempotency key; no second
                    // invoice is posted until the employee submits the next form.
                    $this->replaceMountedAction('createWorkspaceTaxInvoice');

                    return response()->streamDownload(
                        static function () use ($pdfContent): void {
                            echo $pdfContent;
                        },
                        $filename,
                        ['Content-Type' => 'application/pdf'],
                    );
                }),
            Action::make('correctShippedOrder')
                ->label('Post-Shipment Correction')
                ->modalHeading('Audited Post-Shipment Correction')
                ->modalDescription('Only documentary date, external order reference, corrected fulfillment location and authorized selling prices may change. Original shipment, products, quantities, allocations and COGS remain unchanged.')
                ->modalWidth('5xl')
                ->modalSubmitActionLabel('Confirm Correction')
                ->fillForm(function (array $arguments): array {
                    $order = $this->authorizedWorkspaceShippedOrder((int) ($arguments['orderId'] ?? 0));
                    $priceAllowed = app(OrderAuthorization::class)->allows(auth()->user(), OrderPermission::ViewSellingPrice, $order)
                        && app(OrderAuthorization::class)->allows(auth()->user(), OrderPermission::EditSellingPrice, $order);

                    return [
                        'order_date' => $order->order_date?->format('Y-m-d'),
                        'warehouse_id' => app(OrderAmendmentService::class)->correctedWarehouseId($order) ?? $order->warehouse_id,
                        'external_order_number' => $order->external_order_number,
                        'items' => $priceAllowed ? $order->items()->orderBy('line_number')->get()->map(fn ($item): array => [
                            'id' => $item->id, 'product' => $item->sku.' · '.$item->product_name,
                            'selling_price' => $item->selling_price,
                        ])->all() : [],
                        'idempotency_key' => (string) Str::uuid(),
                    ];
                })
                ->schema([
                    Hidden::make('idempotency_key'),
                    DatePicker::make('order_date')->label('Corrected Order Date')->required(),
                    Select::make('warehouse_id')->label('Corrected Warehouse (documentary only)')
                        ->options(function (array $arguments): array {
                            $order = $this->authorizedWorkspaceShippedOrder((int) ($arguments['orderId'] ?? 0));

                            return app(OrderFulfillmentLocationService::class)->options($order->marketplace_platform_id);
                        })->required()->searchable(),
                    TextInput::make('external_order_number')->label('External Order Number')->maxLength(255),
                    Repeater::make('items')->label('Selling Prices (products and quantities locked)')
                        ->visible(fn (array $arguments): bool => $this->workspaceCanEditOrderPrice((int) ($arguments['orderId'] ?? 0)))
                        ->schema([
                            Hidden::make('id'),
                            TextInput::make('product')->label('Product')->disabled()->dehydrated(false),
                            TextInput::make('selling_price')->label('Selling Price (AED)')->numeric()->minValue(0)->required(),
                        ])->addable(false)->deletable(false)->reorderable(false),
                    Textarea::make('reason')->label('Reason for correction')->required()->minLength(5)->maxLength(2000),
                ])
                ->action(function (array $data, array $arguments): void {
                    $order = $this->authorizedWorkspaceShippedOrder((int) ($arguments['orderId'] ?? 0));
                    app(OrderAmendmentService::class)->correctShipped($order, $data, auth()->user());
                    Notification::make()->success()->title('Post-shipment correction recorded')->send();
                }),
            Action::make('createSalesOrder')
                ->label('Create Sales Order')
                ->modalHeading('Create Sales Order')
                ->modalWidth('5xl')
                ->schema([...OrderForm::components(),
                    Checkbox::make('confirm_physical_shipment')
                        ->label('I confirm every item has physically left the warehouse')
                        ->helperText('Required only for Save as Shipped. This action immediately posts fulfillment and stock movements.')
                        ->visible(fn (): bool => auth()->user() instanceof User
                            && app(OrderAuthorization::class)->allows(auth()->user(), OrderPermission::Fulfill))
                        ->dehydrated(),
                ])
                ->visible(fn (): bool => OrderResource::canCreate())
                ->modalSubmitActionLabel('Save & Reserve')
                ->modalSubmitAction(fn (Action $action): Action => $action
                    ->visible(fn (): bool => auth()->user() instanceof User
                        && app(OrderAuthorization::class)->allows(auth()->user(), OrderPermission::Reserve)))
                ->extraModalFooterActions(fn (Action $action): array => [
                    $action->makeModalSubmitAction('saveDraft', arguments: ['mode' => 'draft'])
                        ->label('Save Draft')->color('gray'),
                    ...(auth()->user() instanceof User
                        && app(OrderAuthorization::class)->allows(auth()->user(), OrderPermission::Fulfill)
                            ? [$action->makeModalSubmitAction('saveAsShipped', arguments: ['mode' => 'shipped'])
                                ->label('Save as Shipped')->color('success')]
                            : []),
                ])
                ->action(function (array $data, array $arguments): void {
                    abort_unless(OrderResource::canCreate(), 403);
                    $user = auth()->user();
                    abort_unless($user instanceof User, 403);
                    $mode = $arguments['mode'] ?? 'reserved';
                    abort_unless(in_array($mode, ['reserved', 'draft', 'shipped'], true), 403);
                    $permission = match ($mode) {
                        'draft' => OrderPermission::Create,
                        'shipped' => OrderPermission::Fulfill,
                        default => OrderPermission::Reserve,
                    };
                    abort_unless(app(OrderAuthorization::class)->allows($user, $permission), 403);
                    if ($mode === 'shipped' && ! ($data['confirm_physical_shipment'] ?? false)) {
                        throw ValidationException::withMessages([
                            'confirm_physical_shipment' => 'Confirm that all products have physically left the warehouse before posting shipment.',
                        ]);
                    }

                    $items = collect($data['items'] ?? [])->values()->map(fn (array $item): OrderItemData => new OrderItemData(
                        productId: (int) $item['product_id'],
                        quantity: (int) $item['quantity'],
                        sellingPrice: (string) $item['selling_price'],
                        discountTotal: (string) ($item['discount_total'] ?? '0.00'),
                        vatRate: (string) ($item['vat_rate'] ?? '0.0000'),
                        notes: $item['notes'] ?? null,
                        salesConfigurationId: filled($item['sales_configuration_id'] ?? null) ? (int) $item['sales_configuration_id'] : null,
                        upgradeRecipeId: filled($item['upgrade_recipe_id'] ?? null) ? (int) $item['upgrade_recipe_id'] : null,
                        allocationSources: self::workspaceAllocationSources($item['allocation_sources'] ?? []),
                    ))->all();

                    $dto = new SaveAndReserveOrderData(
                        warehouseId: (int) $data['warehouse_id'],
                        platformId: filled($data['marketplace_platform_id'] ?? null) ? (int) $data['marketplace_platform_id'] : null,
                        externalOrderNumber: $data['external_order_number'] ?? null,
                        orderDate: (string) $data['order_date'],
                        handledByEmployeeId: filled($data['handled_by_employee_id'] ?? null) ? (int) $data['handled_by_employee_id'] : null,
                        notes: $data['notes'] ?? null,
                        items: $items,
                        idempotencyKey: (string) $data['idempotency_key'],
                    );

                    match ($mode) {
                        'draft' => app(OrderService::class)->saveDraft($dto, $user),
                        'shipped' => app(SaveAsShippedOrder::class)->handle($dto, $user),
                        default => app(SaveAndReserveOrder::class)->handle($dto, $user),
                    };

                    $this->activeTab = 'orders';
                    $this->resetPage('sales-page');
                    Notification::make()->success()->title(match ($mode) {
                        'draft' => 'Sales Order draft saved',
                        'shipped' => 'Sales Order created and shipped',
                        default => 'Sales Order created and reserved',
                    })->send();
                }),
        ];
    }

    public function workspaceCanCorrectShipped(Order $order): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(OrderAmendmentService::class)->canCorrectShipped($order, $user);
    }

    private function workspaceCanEditOrderPrice(int $id): bool
    {
        $order = $this->authorizedWorkspaceShippedOrder($id);

        return app(OrderAuthorization::class)->allows(auth()->user(), OrderPermission::ViewSellingPrice, $order)
            && app(OrderAuthorization::class)->allows(auth()->user(), OrderPermission::EditSellingPrice, $order);
    }

    private function authorizedWorkspaceShippedOrder(int $id): Order
    {
        abort_unless(static::canAccess() && $id > 0, 403);
        $user = auth()->user();
        abort_unless($user instanceof User
            && app(OrderAuthorization::class)->allows($user, OrderPermission::View), 403);
        $order = app(OrderReadService::class)->orders($user)->whereKey($id)->first();
        abort_unless($order instanceof Order, 404);
        abort_unless(app(OrderAmendmentService::class)->canCorrectShipped($order, $user), 403);

        return $order;
    }

    private static function workspaceAllocationSources(array $rows): ?array
    {
        $sources = collect($rows)
            ->filter(fn ($row): bool => is_array($row) && filled($row['account_id'] ?? null) && (int) ($row['quantity'] ?? 0) > 0)
            ->mapWithKeys(fn (array $row): array => [(int) $row['account_id'] => (int) $row['quantity']])->all();

        return $sources === [] ? null : $sources;
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(NavigationPreferenceService::class)->workspaceModeEnabled($user)
            && (app(OrderAuthorization::class)->allows($user, OrderPermission::View)
                || app(WebSalesAuthorization::class)->allows($user, WebSalesPermission::View)
                || app(WebSalesAuthorization::class)->allows($user, WebSalesPermission::Dashboard)
                || app(QuotationAuthorization::class)->allows($user, QuotationPermission::View));
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $canOrders = app(OrderAuthorization::class)->allows($user, OrderPermission::View);
        $canWebSales = app(WebSalesAuthorization::class)->allows($user, WebSalesPermission::View);
        $canQuotations = app(QuotationAuthorization::class)->allows($user, QuotationPermission::View);
        $canDashboard = app(WebSalesAuthorization::class)->allows($user, WebSalesPermission::Dashboard);

        // Always start with the existing responsibility-scoped read services.
        $ordersQuery = $canOrders ? app(OrderReadService::class)->orders($user) : null;
        $webSalesQuery = $canWebSales ? app(WebSalesReadService::class)->orders($user) : null;

        // Quantities are operational data; selling amounts retain their
        // independent Access Control gates inside the existing read services.
        $ordersQuery?->withCount('items')->withSum('items as total_units', 'ordered_quantity');

        foreach ([$ordersQuery, $webSalesQuery] as $query) {
            if ($query === null) {
                continue;
            }

            if ($this->status !== 'all' && in_array($this->status, array_column(OrderStatus::cases(), 'value'), true)) {
                $query->where('orders.status', $this->status);
            }
            if ($this->from !== '' && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $this->from)) {
                $query->whereDate('orders.order_date', '>=', $this->from);
            }
            if ($this->to !== '' && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $this->to)) {
                $query->whereDate('orders.order_date', '<=', $this->to);
            }
            if (trim($this->search) !== '') {
                $needle = addcslashes(trim($this->search), '%_\\\\');
                $query->where('orders.reference', 'like', '%'.$needle.'%');
            }
        }

        if ($webSalesQuery !== null && $this->channel !== '' && in_array($this->channel, array_column(WebSalesChannel::cases(), 'value'), true)) {
            $webSalesQuery->where('orders.web_sales_channel', $this->channel);
        }
        if (trim($this->customer) !== '') {
            $needle = addcslashes(trim($this->customer), '%_\\\\');
            // Web Sales has a customer name on orders; ordinary Sales Orders
            // do not share the same denormalized customer-name field.
            if ($webSalesQuery !== null) {
                $webSalesQuery->where('orders.customer_name', 'like', '%'.$needle.'%');
            }
        }

        // Pagination executes against the pre-existing responsibility-scoped queries.
        // Explicit page names prevent Sales and Web Sales from interfering with one another.
        // Only paginate the table currently visible (or both on Overview,
        // which uses their first rows for recent updates). Inactive modules
        // retain permission-scoped counts without loading their record pages.
        $orders = $ordersQuery !== null && in_array($this->activeTab, ['overview', 'orders'], true)
            ? $ordersQuery->with('items:id,order_id,product_name,sku')->latest('orders.order_date')->paginate(10, pageName: 'sales-page')
            : collect();
        $webSales = $webSalesQuery !== null && in_array($this->activeTab, ['overview', 'webSales'], true)
            ? $webSalesQuery->latest('orders.order_date')->paginate(10, pageName: 'web-sales-page')
            : collect();

        $quotations = ($canQuotations && $this->activeTab === 'quotations')
            ? QuotationResource::getEloquentQuery()->latest('created_at')->paginate(10, pageName: 'quotation-page')
            : collect();

        $salesCounts = [];
        if ($canOrders) {
            $salesCounts['orders'] = $orders instanceof LengthAwarePaginator
                ? $orders->total() : ($ordersQuery?->count() ?? 0);
        }
        if ($canWebSales) {
            $salesCounts['webSales'] = $webSales instanceof LengthAwarePaginator
                ? $webSales->total() : ($webSalesQuery?->count() ?? 0);
        }
        if ($canQuotations) {
            $salesCounts['quotations'] = QuotationResource::getEloquentQuery()->count();
        }
        if (TaxInvoiceResource::canViewAny()) {
            $salesCounts['taxInvoices'] = TaxInvoiceResource::getEloquentQuery()->count();
        }

        // Reference-style overview panels use only already authorized
        // read-service records. Financial values remain separately gated.
        $salesAttention = collect();
        $salesRecentUpdates = collect();
        if ($this->activeTab === 'overview') {
            $salesAttention = app(ErpDashboardService::class)
                ->forUser($user, widgetKeys: ['attention'])['attention']
                ->filter(fn (array $alert): bool => preg_match(
                    '/order|sales|shipment|dispatch|invoice|payment|customer/i',
                    (string) ($alert['label'] ?? '')
                ) === 1)
                ->take(8)->values();

            // Sales-only, truthful operational alerts. Each query retains
            // the exact existing Order / Web Sales responsibility scope.
            if ($canOrders) {
                foreach ([
                    OrderStatus::PendingReview->value => 'Sales orders pending review',
                    OrderStatus::Processing->value => 'Sales orders processing',
                ] as $status => $label) {
                    $count = app(OrderReadService::class)->orders($user)
                        ->where('orders.status', $status)->count();
                    if ($count > 0) {
                        $salesAttention->prepend([
                            'label' => $label, 'count' => $count,
                            'url' => OrderResource::getUrl(), 'priority' => 10,
                        ]);
                    }
                }
            }
            if ($canWebSales) {
                $count = app(WebSalesReadService::class)->orders($user)
                    ->where('orders.status', OrderStatus::PendingReview->value)->count();
                if ($count > 0) {
                    $salesAttention->prepend([
                        'label' => 'Web Sales awaiting review',
                        'count' => $count,
                        'url' => WebSalesOrderResource::getUrl(),
                        'priority' => 10,
                    ]);
                }
            }
            $salesAttention = $salesAttention->take(8)->values();

            $salesRecentUpdates = collect();
            if ($canOrders) {
                $salesRecentUpdates = $salesRecentUpdates->concat(
                    $orders->getCollection()->take(5)->map(fn ($order): array => [
                        'reference' => $order->reference,
                        'date' => $order->order_date?->format('d M Y') ?? '—',
                        'timestamp' => $order->updated_at?->timestamp ?? 0,
                        'source' => 'Sales Order',
                        'customer' => '—',
                        'status' => $order->status?->getLabel() ?? 'Unknown',
                        'units' => (int) ($order->total_units ?? 0),
                        'url' => OrderResource::getUrl('view', ['record' => $order]),
                    ])
                );
            }
            if ($canWebSales) {
                $salesRecentUpdates = $salesRecentUpdates->concat(
                    $webSales->getCollection()->take(5)->map(fn ($order): array => [
                        'reference' => $order->reference,
                        'date' => $order->order_date?->format('d M Y') ?? '—',
                        'timestamp' => $order->updated_at?->timestamp ?? 0,
                        'source' => $order->web_sales_channel?->getLabel() ?? 'Web Sales',
                        'customer' => $order->customer_name ?? '—',
                        'status' => $order->status?->getLabel() ?? 'Unknown',
                        'units' => (int) ($order->units ?? 0),
                        'url' => WebSalesOrderResource::getUrl('view', ['record' => $order]),
                    ])
                );
            }
            $salesRecentUpdates = $salesRecentUpdates
                ->sortByDesc('timestamp')->take(8)->values();
        }

        $selectedExtra = $this->activeTab === 'extra' && $this->activeExtraModule !== null
            ? $this->extraModules()->get($this->activeExtraModule)
            : null;
        $extraPreview = is_array($selectedExtra)
            ? app(WorkspaceRecordPreviewService::class)->forLink($selectedExtra)
            : null;

        $quickViewOrder = $this->loadQuickViewOrder($user);

        return [
            'quickViewOrder' => $quickViewOrder,
            'extraPreview' => $extraPreview,
            'salesCounts' => $salesCounts,
            'salesAttention' => $salesAttention,
            'salesRecentUpdates' => $salesRecentUpdates,
            'quotations' => $quotations,
            'orders' => $orders,
            'webSales' => $webSales,
            'canSeeOrderValues' => $canOrders && app(OrderAuthorization::class)->allows($user, OrderPermission::ViewSellingPrice),
            'canSeeWebSalesValues' => $canWebSales && app(WebSalesAuthorization::class)->allows($user, WebSalesPermission::ViewRevenue),
            'extraModules' => $this->extraModules(),
            'links' => array_filter([
                'orders' => $canOrders ? OrderResource::getUrl() : null,
                'createOrder' => OrderResource::canCreate() ? OrderResource::getUrl('create') : null,
                'createWebSale' => WebSalesOrderResource::canCreate() ? WebSalesOrderResource::getUrl('create') : null,
                'createQuotation' => QuotationResource::canCreate() ? QuotationResource::getUrl('create') : null,
                'webSales' => $canWebSales ? WebSalesOrderResource::getUrl() : null,
                'quotations' => $canQuotations ? QuotationResource::getUrl() : null,
                'dashboard' => $canDashboard ? WebSalesDashboard::getUrl() : null,
            ]),
        ];
    }

    /**
     * Open a read-only record preview without leaving the current workspace.
     * Both the initial action and subsequent renders reauthorize by role,
     * permission and responsibility scope. Not a fulfillment action.
     */
    public function openOrderQuickView(string $type, int $id): void
    {
        abort_unless(static::canAccess() && in_array($type, ['orders', 'webSales'], true) && $id > 0, 403);

        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        // Do not use Order::find(): existing read services enforce record
        // visibility and select only permitted commercial fields.
        $query = $type === 'orders'
            ? app(OrderReadService::class)->orders($user)
            : app(WebSalesReadService::class)->orders($user);

        abort_unless($query->whereKey($id)->exists(), 404);

        $this->quickViewOrderId = $id;
        $this->quickViewOrderType = $type;
        $this->confirmQuickViewReserve = false;
        $this->pendingWebSaleOperation = null;
    }

    public function closeOrderQuickView(): void
    {
        $this->quickViewOrderId = null;
        $this->quickViewOrderType = null;
        $this->confirmQuickViewReserve = false;
        $this->pendingWebSaleOperation = null;
    }

    /**
     * Two explicit clicks are required before reserving stock. The operation
     * re-resolves the record under responsibility scope and relies on the
     * existing transactional ReserveDraftOrder service for all stock checks.
     */
    public function prepareQuickViewReserve(): void
    {
        $this->authorizedQuickViewDraft();
        $this->confirmQuickViewReserve = true;
    }

    public function reserveQuickViewDraft(): void
    {
        abort_unless($this->confirmQuickViewReserve, 403);
        $order = $this->authorizedQuickViewDraft();
        // A failed attempt must require fresh explicit confirmation.
        $this->confirmQuickViewReserve = false;

        try {
            app(ReserveDraftOrder::class)->handle($order, auth()->user());
            Notification::make()->success()->title('Sales Order reserved successfully')->send();
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (ValidationException $e) {
            Notification::make()->warning()->title('Cannot reserve Sales Order')
                ->body(collect($e->errors())->flatten()->first() ?? 'Review stock allocation and try again.')->send();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->danger()->title('Reservation was not completed')
                ->body('Use the full order workflow to review the issue.')->send();
        }
    }

    private function authorizedQuickViewDraft(): Order
    {
        abort_unless(static::canAccess()
            && $this->quickViewOrderType === 'orders'
            && $this->quickViewOrderId !== null
            && $this->quickViewOrderId > 0, 403);
        $user = auth()->user();
        abort_unless($user instanceof User
            && app(OrderAuthorization::class)->allows($user, OrderPermission::View), 403);
        $order = app(OrderReadService::class)->orders($user)
            ->whereKey($this->quickViewOrderId)->firstOrFail();
        abort_unless($order->status === OrderStatus::Draft
            && app(OrderAuthorization::class)->allows($user, OrderPermission::Reserve, $order), 403);

        return $order;
    }

    /**
     * Web Sales fulfillment stays inside the original WebSalesService, including
     * manual sourcing, reservation, invoice and stock movement controls.
     * Renewed QC readiness is independently checked before every shipment.
     */
    public function prepareQuickViewWebSale(string $operation): void
    {
        abort_unless(in_array($operation, ['ship', 'deliver'], true), 403);
        $this->authorizedWebSaleOperation($operation);
        $this->pendingWebSaleOperation = $operation;
    }

    public function cancelQuickViewWebSale(): void
    {
        $this->pendingWebSaleOperation = null;
    }

    public function submitQuickViewWebSale(): void
    {
        abort_unless(in_array($this->pendingWebSaleOperation, ['ship', 'deliver'], true), 403);
        $operation = $this->pendingWebSaleOperation;
        $order = $this->authorizedWebSaleOperation($operation);
        $this->pendingWebSaleOperation = null;

        try {
            if ($operation === 'ship') {
                app(WebSalesService::class)->ship($order, (string) str()->uuid(), auth()->user());
            } else {
                app(WebSalesService::class)->deliver($order, auth()->user());
            }
            Notification::make()->success()->title(
                $operation === 'ship' ? 'Web Sale marked Shipped' : 'Web Sale marked Delivered'
            )->send();
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (ValidationException|InvalidOrderTransitionException $e) {
            Notification::make()->warning()->title('Web Sale processing was not completed')
                ->body($e instanceof ValidationException
                    ? (collect($e->errors())->flatten()->first() ?? 'Review order status.')
                    : $e->getMessage())->send();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->danger()->title('Web Sale processing failed')
                ->body('Open the full Web Sale to review fulfillment requirements.')->send();
        }
    }

    private function authorizedWebSaleOperation(string $operation): Order
    {
        abort_unless(static::canAccess() && $this->quickViewOrderType === 'webSales'
            && $this->quickViewOrderId !== null && $this->quickViewOrderId > 0, 403);
        $user = auth()->user();
        abort_unless($user instanceof User
            && app(WebSalesAuthorization::class)->allows($user, WebSalesPermission::View), 403);

        $order = app(WebSalesReadService::class)->orders($user)
            ->whereKey($this->quickViewOrderId)->first();
        abort_unless($order instanceof Order, 404);

        if ($operation === 'ship') {
            abort_unless($this->webSaleCanShip($order, $user), 403);
        } elseif ($operation === 'deliver') {
            abort_unless($this->webSaleCanDeliver($order, $user), 403);
        } else {
            abort(403);
        }

        return $order;
    }

    private function webSaleQcReady(Order $order, User $user): bool
    {
        $readiness = app(RenewedQcDispatchService::class)->readiness($order);

        return $readiness['status'] === 'not_required' || (
            $readiness['status'] === 'ready'
            && app(QcAuthorization::class)->allows($user, QcPermission::ShipDispatch)
        );
    }

    private function webSaleCanShip(Order $order, User $user): bool
    {
        return in_array($order->status, [OrderStatus::Reserved, OrderStatus::PendingReview], true)
            && app(WebSalesAuthorization::class)->allows($user, WebSalesPermission::Update, $order)
            && app(OrderAuthorization::class)->allows($user, OrderPermission::Fulfill, $order)
            && $this->webSaleQcReady($order, $user);
    }

    private function webSaleCanDeliver(Order $order, User $user): bool
    {
        return $order->status === OrderStatus::Fulfilled && $order->delivered_at === null
            && app(WebSalesAuthorization::class)->allows($user, WebSalesPermission::Update, $order);
    }

    private function loadQuickViewOrder(User $user): ?array
    {
        if ($this->quickViewOrderId === null || $this->quickViewOrderType === null) {
            return null;
        }

        if (! in_array($this->quickViewOrderType, ['orders', 'webSales'], true)
            || $this->quickViewOrderId < 1 || ! static::canAccess()) {
            $this->closeOrderQuickView();

            return null;
        }

        $type = $this->quickViewOrderType;
        $authorized = $type === 'orders'
            ? app(OrderAuthorization::class)->allows($user, OrderPermission::View)
            : app(WebSalesAuthorization::class)->allows($user, WebSalesPermission::View);

        if (! $authorized) {
            $this->closeOrderQuickView();

            return null;
        }

        $query = $type === 'orders'
            ? app(OrderReadService::class)->orders($user)
            : app(WebSalesReadService::class)->orders($user);

        $order = $query->with([
            'warehouse:id,name',
            // Restrict items to operational fields only; no selling price,
            // cost, line totals or margin is loaded by this preview.
            'items:id,order_id,line_number,sku,product_name,ordered_quantity',
        ])->whereKey($this->quickViewOrderId)->first();

        if ($order === null) {
            // A record may become inaccessible after an assignment change.
            $this->closeOrderQuickView();

            return null;
        }

        return [
            'type' => $type,
            'canCorrectShipped' => $type === 'orders' && $this->workspaceCanCorrectShipped($order),
            'canShip' => $type === 'webSales' && $this->webSaleCanShip($order, $user),
            'canDeliver' => $type === 'webSales' && $this->webSaleCanDeliver($order, $user),
            'qcReady' => $type !== 'webSales' || ! in_array($order->status, [OrderStatus::Reserved, OrderStatus::PendingReview], true) || $this->webSaleQcReady($order, $user),
            'showQcWarning' => $type === 'webSales'
                && in_array($order->status, [OrderStatus::Reserved, OrderStatus::PendingReview], true)
                && app(WebSalesAuthorization::class)->allows($user, WebSalesPermission::Update, $order)
                && app(OrderAuthorization::class)->allows($user, OrderPermission::Fulfill, $order)
                && ! $this->webSaleQcReady($order, $user),
            'canReserve' => $type === 'orders'
                && $order->status === OrderStatus::Draft
                && app(OrderAuthorization::class)->allows($user, OrderPermission::Reserve, $order),
            'reference' => $order->reference,
            'status' => $type === 'webSales' ? $order->webSalesStatusLabel() : ($order->status?->getLabel() ?? 'Unknown'),
            'deliveredAt' => $type === 'webSales' ? $order->delivered_at?->format('d M Y, h:i A') : null,
            'date' => $order->order_date?->format('d M Y') ?? '—',
            'warehouse' => $order->warehouse?->name ?? '—',
            'customer' => $type === 'webSales' ? ($order->customer_name ?? '—') : null,
            'channel' => $type === 'webSales' ? ($order->web_sales_channel?->getLabel() ?? '—') : null,
            'items' => $order->items->map(fn ($item): array => [
                'sku' => $item->sku,
                'name' => $item->product_name,
                'quantity' => (int) $item->ordered_quantity,
            ])->all(),
            'url' => $type === 'orders'
                ? OrderResource::getUrl('view', ['record' => $order])
                : WebSalesOrderResource::getUrl('view', ['record' => $order]),
        ];
    }

    private function extraModules(): Collection
    {
        return collect(app(NavigationPreferenceService::class)->workspaceModuleLinks(auth()->user(), 'Sales'))
            ->reject(fn (array $item): bool => in_array($item['label'], [
                'Sales Orders', 'Web Sales Orders', 'Quotations', 'Web Sales Dashboard', 'Products',
            ], true))
            ->values();
    }

    public function selectExtraModule(int $index): void
    {
        abort_unless(static::canAccess() && $this->extraModules()->has($index), 403);
        $this->activeTab = 'extra';
        $this->activeExtraModule = $index;
    }

    public function setTab(string $tab): void
    {
        /** @var User $user */
        $user = auth()->user();
        abort_unless(static::canAccess(), 403);

        $permitted = match ($tab) {
            'overview' => true,
            'orders' => app(OrderAuthorization::class)->allows($user, OrderPermission::View),
            'webSales' => app(WebSalesAuthorization::class)->allows($user, WebSalesPermission::View),
            'quotations' => app(QuotationAuthorization::class)->allows($user, QuotationPermission::View),
            default => false,
        };
        abort_unless($permitted, 403);
        $this->activeTab = $tab;
        $this->activeExtraModule = null;
        $this->resetPage('sales-page');
        $this->resetPage('web-sales-page');
        $this->resetPage('quotation-page');
    }

    public function clearFilters(): void
    {
        $this->reset(['status', 'search', 'customer', 'channel', 'from', 'to']);
        $this->resetPage('sales-page');
        $this->resetPage('web-sales-page');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'search', 'customer', 'channel', 'from', 'to'], true)) {
            $this->resetPage('sales-page');
            $this->resetPage('web-sales-page');
        }
    }
}
