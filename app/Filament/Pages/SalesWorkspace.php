<?php

namespace App\Filament\Pages;

use App\Enums\OrderPermission;
use App\Enums\WebSalesPermission;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\WebSalesOrders\WebSalesOrderResource;
use App\Models\User;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\WebSalesAuthorization;
use App\Services\Navigation\NavigationPreferenceService;
use App\Services\Orders\OrderReadService;
use App\Services\Orders\WebSalesReadService;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class SalesWorkspace extends Page
{
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
                || app(WebSalesAuthorization::class)->allows($user, WebSalesPermission::View));
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
        $ordersQuery = app(OrderAuthorization::class)->allows($user, OrderPermission::View)
            ? app(OrderReadService::class)->orders($user)
            : null;
        $webSalesQuery = app(WebSalesAuthorization::class)->allows($user, WebSalesPermission::View)
            ? app(WebSalesReadService::class)->orders($user)
            : null;
        foreach ([$ordersQuery, $webSalesQuery] as $query) {
            if ($query !== null && $this->status !== 'all') {
                $query->where('orders.status', $this->status);
            }
            if ($query !== null && $this->from !== '') {
                $query->whereDate('orders.order_date', '>=', $this->from);
            }
            if ($query !== null && $this->to !== '') {
                $query->whereDate('orders.order_date', '<=', $this->to);
            }
        }
        if ($webSalesQuery !== null && $this->channel !== '') {
            $webSalesQuery->where('orders.web_sales_channel', $this->channel);
        }
        if ($ordersQuery !== null && $this->customer !== '') {
            $ordersQuery->where(function ($query): void {
                $query->where('orders.reference', 'like', '%'.$this->customer.'%')->orWhere('orders.notes', 'like', '%'.$this->customer.'%');
            });
        }
        if ($webSalesQuery !== null && $this->customer !== '') {
            $webSalesQuery->where('orders.customer_name', 'like', '%'.$this->customer.'%');
        }
        $orders = $ordersQuery?->latest('orders.order_date')->limit(8)->get() ?? collect();
        $webSales = $webSalesQuery?->latest('orders.order_date')->limit(8)->get() ?? collect();
        $orders = $orders->filter(fn ($order): bool => $this->matches($order->reference, $order->status?->value ?? $order->status));
        $webSales = $webSales->filter(fn ($order): bool => $this->matches($order->reference, $order->status?->value ?? $order->status));

        return [
            'orders' => $orders,
            'webSales' => $webSales,
            'links' => [
                'orders' => OrderResource::getUrl(),
                'webSales' => WebSalesOrderResource::getUrl(),
                'quotations' => QuotationResource::getUrl(),
                'dashboard' => WebSalesDashboard::getUrl(),
            ],
        ];
    }

    private function matches(?string $reference, mixed $status): bool
    {
        $needle = mb_strtolower(trim($this->search));
        $haystack = mb_strtolower(($reference ?? '').' '.(string) $status);
        $statusMatch = $this->status === 'all' || (string) $status === $this->status;

        return $statusMatch && ($needle === '' || str_contains($haystack, $needle));
    }
}
