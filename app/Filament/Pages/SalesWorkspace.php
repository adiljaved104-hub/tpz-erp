<?php

namespace App\Filament\Pages;

use App\Enums\OrderPermission;
use App\Enums\OrderStatus;
use App\Enums\QuotationPermission;
use App\Enums\WebSalesChannel;
use App\Enums\WebSalesPermission;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\WebSalesOrders\WebSalesOrderResource;
use App\Models\User;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\QuotationAuthorization;
use App\Services\Authorization\WebSalesAuthorization;
use App\Services\Navigation\NavigationPreferenceService;
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

        return [
            'orders' => $orders,
            'webSales' => $webSales,
            'canSeeOrderValues' => $canOrders && app(OrderAuthorization::class)->allows($user, OrderPermission::ViewSellingPrice),
            'canSeeWebSalesValues' => $canWebSales && app(WebSalesAuthorization::class)->allows($user, WebSalesPermission::ViewRevenue),
            'links' => array_filter([
                'orders' => $canOrders ? OrderResource::getUrl() : null,
                'createOrder' => OrderResource::canCreate() ? OrderResource::getUrl('create') : null,
                'webSales' => $canWebSales ? WebSalesOrderResource::getUrl() : null,
                'quotations' => $canQuotations ? QuotationResource::getUrl() : null,
                'dashboard' => $canDashboard ? WebSalesDashboard::getUrl() : null,
            ]),
        ];
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
