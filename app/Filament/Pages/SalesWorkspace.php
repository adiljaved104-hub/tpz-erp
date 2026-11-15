<?php

namespace App\Filament\Pages;

use App\Enums\OrderPermission;
use App\Enums\OrderStatus;
use App\Enums\QuotationPermission;
use App\Enums\WebSalesChannel;
use App\Enums\WebSalesPermission;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\TaxInvoices\TaxInvoiceResource;
use App\Filament\Resources\WebSalesOrders\WebSalesOrderResource;
use App\Models\User;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\QuotationAuthorization;
use App\Services\Authorization\WebSalesAuthorization;
use App\Services\Dashboard\ErpDashboardService;
use App\Services\Navigation\NavigationPreferenceService;
use App\Services\Navigation\WorkspaceRecordPreviewService;
use App\Services\Orders\OrderReadService;
use App\Services\Orders\WebSalesReadService;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\WithPagination;

class SalesWorkspace extends Page
{
    use WithPagination;

    protected string $view = 'filament.pages.sales-workspace';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Sales Workspace';

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
        $orders = $ordersQuery?->latest('orders.order_date')->paginate(10, pageName: 'sales-page') ?? collect();
        $webSales = $webSalesQuery?->latest('orders.order_date')->paginate(10, pageName: 'web-sales-page') ?? collect();

        $quotations = ($canQuotations && $this->activeTab === 'quotations')
            ? QuotationResource::getEloquentQuery()->latest('created_at')->paginate(10, pageName: 'quotation-page')
            : collect();

        $salesCounts = [];
        if ($canOrders) {
            $salesCounts['orders'] = $orders instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator
                ? $orders->total() : $orders->count();
        }
        if ($canWebSales) {
            $salesCounts['webSales'] = $webSales instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator
                ? $webSales->total() : $webSales->count();
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
                    '/order|sales|return|claim|complaint|invoice|payment|customer/i',
                    (string) ($alert['label'] ?? '')
                ) === 1)
                ->take(8)->values();

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

        return [
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

    private function extraModules(): \Illuminate\Support\Collection
    {
        return collect(app(NavigationPreferenceService::class)->workspaceModuleLinks(auth()->user(), 'Sales'))
            ->reject(fn (array $item): bool => in_array($item['label'], [
                'Sales Orders', 'Web Sales Orders', 'Quotations', 'Web Sales Dashboard',
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
