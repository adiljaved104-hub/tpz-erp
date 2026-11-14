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
        $orders = app(OrderAuthorization::class)->allows($user, OrderPermission::View)
            ? app(OrderReadService::class)->orders($user)->latest('orders.order_date')->limit(8)->get()
            : collect();
        $webSales = app(WebSalesAuthorization::class)->allows($user, WebSalesPermission::View)
            ? app(WebSalesReadService::class)->orders($user)->latest('orders.order_date')->limit(8)->get()
            : collect();
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
