<?php

namespace App\Filament\Widgets;

use App\Enums\InventoryPermission;
use App\Enums\CustomerReturnPermission;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Returns\CustomerReturnReadService;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\CustomerReturns\CustomerReturnResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Filament\Resources\StockRequests\StockRequestResource;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Dashboard\DashboardPreferenceService;
use App\Services\Dashboard\DashboardWidgetRegistry;
use App\Services\Dashboard\ErpDashboardService;
use App\Services\Orders\OrderReadService;
use App\Services\Orders\WebSalesReadService;
use App\Services\Inventory\StockRequestService;
use App\Services\StockTransfers\StockTransferReadService;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\WebSalesAuthorization;
use App\Enums\OrderPermission;
use App\Enums\WebSalesPermission;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Services\Inventory\InventoryReadService;
use App\Services\Navigation\NavigationPreferenceService;
use App\Services\UserTimezoneResolver;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ErpDashboardOverview extends Widget
{
    protected string $view = 'filament.widgets.erp-dashboard-overview';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -100;

    protected static bool $isLazy = false;

    public string $period = 'today';

    public ?string $customFrom = null;

    public ?string $customTo = null;

    public ?string $appliedCustomFrom = null;

    public ?string $appliedCustomTo = null;

    public string $inventoryScope = 'company';

    public ?string $inventoryTeamId = null;

    public ?string $inventoryEmployeeId = null;

    /** @var array<int, string> */
    public array $widgetOrder = [];

    /** @var array<int, string> */
    public array $hiddenWidgets = [];

    public bool $customizeMode = false;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->employee?->status === true;
    }

    public function mount(): void
    {
        $this->loadPreferenceState();
    }

    public function setPeriod(string $period): void
    {
        $this->period = in_array($period, ['today', 'week', 'month', 'custom'], true) ? $period : 'today';

        if ($this->period === 'custom' && ($this->customFrom === null || $this->customTo === null)) {
            $today = CarbonImmutable::today(app(UserTimezoneResolver::class)->resolve(auth()->user()));
            $this->customFrom = $today->subDays(29)->toDateString();
            $this->customTo = $today->toDateString();
            $this->appliedCustomFrom = $this->customFrom;
            $this->appliedCustomTo = $this->customTo;
        }
    }

    public function applyCustomRange(): void
    {
        $this->resetValidation(['customFrom', 'customTo']);
        $validated = $this->validate([
            'customFrom' => ['required', 'date_format:Y-m-d'],
            'customTo' => ['required', 'date_format:Y-m-d', 'after_or_equal:customFrom'],
        ], [
            'customTo.after_or_equal' => 'The To Date must be on or after the From Date.',
        ]);

        $timezone = app(UserTimezoneResolver::class)->resolve(auth()->user());
        $from = CarbonImmutable::createFromFormat('Y-m-d', $validated['customFrom'], $timezone);
        $to = CarbonImmutable::createFromFormat('Y-m-d', $validated['customTo'], $timezone);
        if ($from->diffInDays($to) > 366) {
            $this->addError('customTo', 'The custom Dashboard range cannot exceed 366 days.');

            return;
        }

        $this->appliedCustomFrom = $validated['customFrom'];
        $this->appliedCustomTo = $validated['customTo'];
        $this->period = 'custom';
    }

    public function updatedInventoryScope(string $scope): void
    {
        $this->inventoryScope = in_array($scope, ['company', 'team', 'employee'], true) ? $scope : 'company';
        $this->inventoryTeamId = null;
        $this->inventoryEmployeeId = null;
    }

    public function toggleCustomizeMode(): void
    {
        $this->customizeMode = ! $this->customizeMode;
    }

    public function finishCustomizing(): void
    {
        $this->customizeMode = false;
    }

    public function toggleWidget(string $key): void
    {
        $this->assertAuthorizedWidget($key);

        if (in_array($key, $this->hiddenWidgets, true)) {
            $this->hiddenWidgets = array_values(array_diff($this->hiddenWidgets, [$key]));
        } else {
            $this->hiddenWidgets[] = $key;
        }

        $this->persistPreference('Dashboard visibility saved');
    }

    /** @param array<int, mixed> $orderedKeys */
    public function reorderWidgets(array $orderedKeys): void
    {
        /** @var User $user */
        $user = auth()->user();
        $visible = array_values(array_diff($this->widgetOrder, $this->hiddenWidgets));
        $orderedKeys = array_values($orderedKeys);

        if (count($orderedKeys) !== count(array_unique($orderedKeys)) || array_diff($orderedKeys, $visible) !== [] || array_diff($visible, $orderedKeys) !== []) {
            throw ValidationException::withMessages(['layout' => 'The Dashboard order is invalid.']);
        }

        $this->widgetOrder = array_values(array_merge($orderedKeys, $this->hiddenWidgets));
        app(DashboardPreferenceService::class)->save(
            $user,
            DashboardWidgetRegistry::DASHBOARD_KEY,
            $this->widgetOrder,
            $this->hiddenWidgets,
        );
        $this->savedNotification('Dashboard layout saved');
    }

    public function moveWidget(string $key, string $direction): void
    {
        $this->assertAuthorizedWidget($key);
        if (! in_array($direction, ['up', 'down'], true)) {
            throw ValidationException::withMessages(['layout' => 'The Dashboard move direction is invalid.']);
        }

        $index = array_search($key, $this->widgetOrder, true);
        if ($index === false) {
            return;
        }

        $target = $direction === 'up' ? $index - 1 : $index + 1;
        if ($target < 0 || $target >= count($this->widgetOrder)) {
            return;
        }

        [$this->widgetOrder[$index], $this->widgetOrder[$target]] = [$this->widgetOrder[$target], $this->widgetOrder[$index]];
        $this->widgetOrder = array_values($this->widgetOrder);
        $this->persistPreference('Dashboard order saved');
    }

    public function resetDashboard(): void
    {
        /** @var User $user */
        $user = auth()->user();
        app(DashboardPreferenceService::class)->reset($user);
        $this->loadPreferenceState();
        $this->savedNotification('Dashboard reset to your role default');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();

        $registry = app(DashboardWidgetRegistry::class);
        $authorizedDefinitions = $registry->authorized($user);
        $authorizedKeys = $authorizedDefinitions->keys()->all();
        $layout = array_values(array_unique(array_merge(
            array_values(array_intersect($this->widgetOrder, $authorizedKeys)),
            array_values(array_diff($authorizedKeys, $this->widgetOrder)),
        )));
        $hidden = array_values(array_intersect($this->hiddenWidgets, $authorizedKeys));
        $visible = array_values(array_diff($layout, $hidden));

        $dashboardData = app(ErpDashboardService::class)->forUser(
            $user,
            $this->period,
            $this->appliedCustomFrom,
            $this->appliedCustomTo,
            $this->inventoryScope,
            filled($this->inventoryTeamId) ? (int) $this->inventoryTeamId : null,
            filled($this->inventoryEmployeeId) ? (int) $this->inventoryEmployeeId : null,
            $visible,
        );

        return $dashboardData + [
            'reference_kpis' => $this->nativeReferenceKpis($user, $dashboardData['cards']),
            'widget_order' => $layout,
            'visible_widget_keys' => $visible,
            'hidden_widget_keys' => $hidden,
            'available_widgets' => $layout === []
                ? collect()
                : collect($layout)->map(fn (string $key): array => $authorizedDefinitions->get($key))->filter()->values(),
            'widget_definitions' => $authorizedDefinitions,
            'customize_mode' => $this->customizeMode,
            'quick_actions' => $this->authorizedQuickActions($user),
            'recent_activity' => $this->authorizedRecentActivity($user),
        ];
    }

    /** @return array<int, array{label: string, value: int, url: string, tone: string}> */
    private function nativeReferenceKpis(User $user, Collection $cards): array
    {
        // Classic remains unchanged. Native preview uses each existing scoped
        // service and independent Access Control permission before counting.
        if (! app(NavigationPreferenceService::class)->workspaceModeEnabled($user)) {
            return [];
        }

        // Period comparisons are recent activity, NOT a percentage change
        // in the all-time total shown on each card.
        $today = CarbonImmutable::now(app(UserTimezoneResolver::class)->resolve($user))->startOfDay();
        $thisPeriod = $today->subDays(6);
        $lastPeriod = $thisPeriod->subDays(7);
        $end = $today->addDay();
        $kpis = [];
        if (app(OrderAuthorization::class)->allows($user, OrderPermission::View)) {
            $orders = app(OrderReadService::class)->orders($user)->whereNull('orders.web_sales_channel');
            $kpis[] = [
                'label' => 'Sales Orders',
                'value' => (clone $orders)->count(),
                'trend' => $this->nativeTrend($orders, 'orders.order_date', $lastPeriod, $thisPeriod, $end, true),
                'url' => \App\Filament\Resources\Orders\OrderResource::getUrl(),
                'tone' => 'blue',
            ];
        }
        if (app(WebSalesAuthorization::class)->allows($user, WebSalesPermission::View)) {
            $webSales = app(WebSalesReadService::class)->scoped($user);
            $kpis[] = [
                'label' => 'Web Sales',
                'value' => (clone $webSales)->count(),
                'trend' => $this->nativeTrend($webSales, 'orders.order_date', $lastPeriod, $thisPeriod, $end, true),
                'url' => \App\Filament\Resources\WebSalesOrders\WebSalesOrderResource::getUrl(),
                'tone' => 'green',
            ];
        }
        if (app(InventoryAuthorization::class)->allows($user, InventoryPermission::ViewStockRequests)) {
            $stockRequests = app(StockRequestService::class)->visibleQuery($user);
            $kpis[] = [
                'label' => 'Stock Requests',
                'value' => (clone $stockRequests)->count(),
                'trend' => $this->nativeTrend($stockRequests, 'created_at', $lastPeriod, $thisPeriod, $end),
                'url' => StockRequestResource::getUrl(),
                'tone' => 'amber',
            ];
        }
        if (StockTransferResource::canViewAny()) {
            $transfers = app(StockTransferReadService::class)->query($user);
            $kpis[] = [
                'label' => 'Transfers (In/Out)',
                'value' => (clone $transfers)->count(),
                'trend' => $this->nativeTrend($transfers, 'stock_transfers.created_at', $lastPeriod, $thisPeriod, $end),
                'url' => StockTransferResource::getUrl(),
                'tone' => 'indigo',
            ];
        }
        $returns = $cards->firstWhere('key', 'returns');
        if (app(CustomerReturnAuthorization::class)->allows($user, CustomerReturnPermission::View)) {
            // Both cards share exactly the responsibility scope of the
            // Customer Returns list. The existing Returns / QC card keeps
            // its existing operational count, while Total Returns shows
            // every return record, including completed and cancelled.
            $allReturns = app(CustomerReturnReadService::class)->query($user);
            if ($returns !== null) {
                $activeReturns = (clone $allReturns)
                    ->whereNotIn('status', ['completed', 'cancelled']);
                $kpis[] = [
                    'label' => 'Returns / QC',
                    'value' => (int) ($returns['value'] ?? 0),
                    'trend' => $this->nativeTrend($activeReturns, 'created_at', $lastPeriod, $thisPeriod, $end),
                    'url' => $returns['url'],
                    'tone' => 'rose',
                ];
            }
            $kpis[] = [
                'label' => 'Total Returns',
                'value' => (clone $allReturns)->count(),
                'trend' => $this->nativeTrend($allReturns, 'created_at', $lastPeriod, $thisPeriod, $end),
                'url' => CustomerReturnResource::getUrl(),
                'tone' => 'rose',
            ];
        }

        return $kpis;
    }

    /**
     * Compare records dated in the most recent 7 local calendar days with the
     * preceding 7 days, preserving the caller's responsibility-scoped query.
     * This indicator measures recent records, not change in the all-time total.
     *
     * @return array{label:string, direction:string, title:string}
     */
    private function nativeTrend(
        Builder $base,
        string $column,
        CarbonImmutable $priorStart,
        CarbonImmutable $currentStart,
        CarbonImmutable $end,
        bool $dateOnly = false,
    ): array {
        $start = $dateOnly ? $priorStart->toDateString() : $priorStart->utc()->toDateTimeString();
        $middle = $dateOnly ? $currentStart->toDateString() : $currentStart->utc()->toDateTimeString();
        $finish = $dateOnly ? $end->toDateString() : $end->utc()->toDateTimeString();

        $previous = (clone $base)->where($column, '>=', $start)->where($column, '<', $middle)->count();
        $current = (clone $base)->where($column, '>=', $middle)->where($column, '<', $finish)->count();

        $label = $previous === 0
            ? ($current > 0 ? 'New' : '—')
            : sprintf('%+.0f%%', (($current - $previous) / $previous) * 100);

        return [
            'label' => $label,
            'direction' => $current > $previous ? 'up' : ($current < $previous ? 'down' : 'flat'),
            'title' => "Last 7 days: {$current} · Previous 7 days: {$previous}",
        ];
    }

    /** @return array{enabled: bool, movements: Collection} */
    private function authorizedRecentActivity(User $user): array
    {
        $enabled = app(NavigationPreferenceService::class)->workspaceModeEnabled($user)
            && app(InventoryAuthorization::class)->allows($user, InventoryPermission::ViewMovements);

        return [
            'enabled' => $enabled,
            'movements' => $enabled
                ? app(InventoryReadService::class)->movements($user)->latest('occurred_at')->limit(5)->get()
                : collect(),
        ];
    }

    /** @return array<int, array{label: string, url: string, icon: string}> */
    private function authorizedQuickActions(User $user): array
    {
        if (! app(NavigationPreferenceService::class)->workspaceModeEnabled($user)) {
            return [];
        }

        $actions = [];
        foreach ([
            [OrderResource::class, 'Create Sales Order', 'heroicon-o-shopping-bag'],
            [PurchaseResource::class, 'Create Purchase', 'heroicon-o-shopping-cart'],
            [StockRequestResource::class, 'Create Stock Request', 'heroicon-o-clipboard-document-list'],
            [CustomerReturnResource::class, 'Record Customer Return', 'heroicon-o-arrow-uturn-left'],
        ] as [$resource, $label, $icon]) {
            if ($resource::canCreate()) {
                $actions[] = ['label' => $label, 'url' => $resource::getUrl('create'), 'icon' => $icon];
            }
        }

        return $actions;
    }

    private function loadPreferenceState(): void
    {
        /** @var User $user */
        $user = auth()->user();
        $state = app(DashboardPreferenceService::class)->resolve($user);
        $this->widgetOrder = $state['layout'];
        $this->hiddenWidgets = $state['hidden'];
    }

    private function persistPreference(string $message): void
    {
        /** @var User $user */
        $user = auth()->user();
        app(DashboardPreferenceService::class)->save(
            $user,
            DashboardWidgetRegistry::DASHBOARD_KEY,
            $this->widgetOrder,
            $this->hiddenWidgets,
        );
        $this->savedNotification($message);
    }

    private function assertAuthorizedWidget(string $key): void
    {
        /** @var User $user */
        $user = auth()->user();
        if (! app(DashboardWidgetRegistry::class)->isAuthorized($user, $key)) {
            throw ValidationException::withMessages(['widget' => 'This Dashboard widget is not available for your access.']);
        }
    }

    private function savedNotification(string $title): void
    {
        Notification::make()->success()->title($title)->duration(1800)->send();
    }
}
