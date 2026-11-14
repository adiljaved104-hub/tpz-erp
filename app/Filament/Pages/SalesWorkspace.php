<?php

namespace App\Filament\Pages;

use App\Enums\OrderPermission;
use App\Enums\QuotationPermission;
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

        // Existing scoped read services remain the source of truth; never query
        // raw orders or bypass team/employee responsibility restrictions.
        $orders = $canOrders
            ? app(OrderReadService::class)->orders($user)->latest('orders.order_date')->limit(8)->get()
            : collect();
        $webSales = $canWebSales
            ? app(WebSalesReadService::class)->orders($user)->latest('orders.order_date')->limit(8)->get()
            : collect();

        $orders = $orders->filter(fn ($order): bool => $this->matches($order->reference, $order->status?->value ?? $order->status));
        $webSales = $webSales->filter(fn ($order): bool => $this->matches($order->reference, $order->status?->value ?? $order->status));

        return [
            'orders' => $orders,
            'webSales' => $webSales,
            'links' => array_filter([
                'orders' => $canOrders ? OrderResource::getUrl() : null,
                'webSales' => $canWebSales ? WebSalesOrderResource::getUrl() : null,
                'quotations' => $canQuotations ? QuotationResource::getUrl() : null,
                'dashboard' => $canDashboard ? WebSalesDashboard::getUrl() : null,
            ]),
        ];
    }

    private function matches(?string $reference, mixed $status): bool
    {
        $needle = mb_strtolower(trim($this->search));
        $haystack = mb_strtolower(($reference ?? '').' '.(string) $status);
        $statusMatch = $this->status === 'all' || (string) $status === $this->status
            || ($this->status === 'shipped' && (string) $status === 'fulfilled');

        return $statusMatch && ($needle === '' || str_contains($haystack, $needle));
    }
}
