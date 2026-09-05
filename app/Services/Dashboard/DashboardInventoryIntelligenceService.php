<?php

namespace App\Services\Dashboard;

use App\Enums\InventoryPermission;
use App\Enums\OrderPermission;
use App\Enums\ResponsibilityPermission;
use App\Filament\Pages\Inventory\MyInventory;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\ProductInventories\ProductInventoryResource;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Team;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\ResponsibilityAuthorization;
use App\Services\Orders\OrderResponsibilityScopeService;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardInventoryIntelligenceService
{
    public const LOW_STOCK_THRESHOLD = 2;

    public function __construct(
        private readonly InventoryAuthorization $inventoryAuthorization,
        private readonly ResponsibilityAuthorization $responsibilityAuthorization,
        private readonly ResponsibilityProductScopeService $responsibilityScope,
        private readonly OrderAuthorization $orderAuthorization,
        private readonly OrderResponsibilityScopeService $orderScope,
    ) {}

    /** @return array<string, mixed>|null */
    public function forUser(
        User $viewer,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $scope = 'company',
        ?int $teamId = null,
        ?int $employeeId = null,
    ): ?array {
        $canViewCompany = $this->inventoryAuthorization->allows($viewer, InventoryPermission::View);
        $canViewUnscopedCompany = $canViewCompany && ! $this->responsibilityScope->requiresScope($viewer);
        $canViewOwn = $this->responsibilityAuthorization->allows($viewer, ResponsibilityPermission::ViewOwn);
        $canViewAllScopes = $this->responsibilityAuthorization->allows($viewer, ResponsibilityPermission::ViewAll);
        $canViewTeamScopes = $this->responsibilityAuthorization->allows($viewer, ResponsibilityPermission::ViewTeam);
        $canSelectScope = $canViewAllScopes || $canViewTeamScopes;

        if (! $canViewCompany && ! $canViewOwn) {
            return null;
        }

        [$scope, $scopeLabel, $scopeUsers] = $this->resolveScope(
            $viewer,
            $scope,
            $teamId,
            $employeeId,
            $canViewUnscopedCompany,
            $canViewAllScopes,
            $canViewTeamScopes,
        );
        $inventoryRows = $this->inventoryRows($scopeUsers);
        $sales = $this->topSellers($viewer, $from, $to);
        $salesByProduct = $sales->keyBy('product_id');

        $rows = $inventoryRows->map(function (object $row) use ($salesByProduct, $viewer): array {
            $sellable = (int) $row->sellable;
            $sold = (int) ($salesByProduct->get($row->product_id)['sold_quantity'] ?? 0);
            $state = $sellable <= 0 ? 'Out of Stock' : ($sellable <= self::LOW_STOCK_THRESHOLD ? 'Low Stock' : 'In Stock');

            return [
                'product_id' => (int) $row->product_id,
                'product' => $row->name,
                'product_label' => $this->productLabel($row->brand, $row->model, $row->name),
                'sku' => $row->sku,
                'sellable' => $sellable,
                'reserved' => (int) $row->reserved,
                'sold_quantity' => $sold,
                'state' => $state,
                'priority' => ($sold > 0 && $sellable <= 0) ? 50 : (($sold > 0 && $sellable <= self::LOW_STOCK_THRESHOLD) ? 40 : ($sellable <= 0 ? 30 : ($sellable <= self::LOW_STOCK_THRESHOLD ? 20 : 0))),
                'url' => $this->inventoryAuthorization->allows($viewer, InventoryPermission::View)
                    ? ProductInventoryResource::getUrl()
                    : MyInventory::getUrl(),
            ];
        });

        $low = $rows->where('sellable', '>', 0)->where('sellable', '<=', self::LOW_STOCK_THRESHOLD);
        $out = $rows->where('sellable', '<=', 0);

        return [
            'scope' => $scope,
            'scope_label' => $scopeLabel,
            'can_select_scope' => $canSelectScope,
            'can_view_company_scope' => $canViewAllScopes,
            'company_scope_label' => $canViewAllScopes ? 'Company' : 'My Responsibilities',
            'team_options' => $this->teamOptions($viewer, $canViewAllScopes, $canViewTeamScopes),
            'employee_options' => $this->employeeOptions($viewer, $canViewAllScopes, $canViewTeamScopes),
            'sellable_units' => $rows->sum('sellable'),
            'low_stock_count' => $low->count(),
            'out_of_stock_count' => $out->count(),
            'attention' => $rows->where('priority', '>', 0)->sortByDesc('priority')->take(8)->values(),
            'top_sellers' => $sales->take(5)->values(),
            'fast_selling_low_stock' => $rows->where('sold_quantity', '>', 0)->where('sellable', '<=', self::LOW_STOCK_THRESHOLD)->sortByDesc('sold_quantity')->take(5)->values(),
            'threshold' => self::LOW_STOCK_THRESHOLD,
            'period_from' => $from->toDateString(),
            'period_to' => $to->toDateString(),
        ];
    }

    /** @return array{string, string, Collection<int, int>|null} */
    private function resolveScope(User $viewer, string $scope, ?int $teamId, ?int $employeeId, bool $canViewCompany, bool $canViewAllScopes, bool $canViewTeamScopes): array
    {
        if (($canViewAllScopes || $canViewTeamScopes) && $scope === 'employee') {
            $employee = $employeeId === null ? null : Employee::query()
                ->where('status', true)
                ->whereNotNull('user_id')
                ->when(! $canViewAllScopes, fn ($query) => $query->where('team_id', $viewer->employee->team_id))
                ->find($employeeId);

            return $employee !== null
                ? ['employee', $employee->name, collect([(int) $employee->id])]
                : ['employee', 'Select an Employee', collect()];
        }

        if (($canViewAllScopes || $canViewTeamScopes) && $scope === 'team') {
            $team = $teamId === null ? null : Team::query()
                ->when(! $canViewAllScopes, fn ($query) => $query->whereKey($viewer->employee->team_id))
                ->find($teamId);
            $employeeIds = $teamId === null
                ? collect()
                : Employee::query()->where('status', true)->where('team_id', $team?->id ?? 0)->whereNotNull('user_id')->pluck('id')->map(fn ($id): int => (int) $id);

            return ['team', $team?->name ?? 'Select a Team', $employeeIds];
        }

        if ($canViewCompany && ($scope === 'company' || (! $canViewAllScopes && ! $canViewTeamScopes))) {
            return ['company', 'Company', null];
        }

        return ['employee', 'My Responsibilities', collect([(int) $viewer->employee->id])];
    }

    /** @return array<int, string> */
    private function teamOptions(User $viewer, bool $canViewAll, bool $canViewTeam): array
    {
        if (! $canViewAll && ! $canViewTeam) {
            return [];
        }

        return Team::query()->where('status', true)
            ->when(! $canViewAll, fn ($query) => $query->whereKey($viewer->employee->team_id))
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<int, string> */
    private function employeeOptions(User $viewer, bool $canViewAll, bool $canViewTeam): array
    {
        if (! $canViewAll && ! $canViewTeam) {
            return [];
        }

        return Employee::query()->where('status', true)->whereNotNull('user_id')
            ->when(! $canViewAll, fn ($query) => $query->where('team_id', $viewer->employee->team_id))
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @param Collection<int, int>|null $scopeEmployeeIds */
    private function inventoryRows(?Collection $scopeEmployeeIds): Collection
    {
        $query = DB::table('product_inventories as dashboard_pi')
            ->join('products as dashboard_products', 'dashboard_products.id', '=', 'dashboard_pi.product_id')
            ->select(['dashboard_products.id as product_id', 'dashboard_products.sku', 'dashboard_products.name', 'dashboard_products.brand', 'dashboard_products.model'])
            ->selectRaw('SUM(dashboard_pi.available_quantity - dashboard_pi.reserved_quantity) AS sellable')
            ->selectRaw('SUM(dashboard_pi.reserved_quantity) AS reserved')
            ->groupBy('dashboard_products.id', 'dashboard_products.sku', 'dashboard_products.name', 'dashboard_products.brand', 'dashboard_products.model');

        if ($scopeEmployeeIds !== null) {
            if ($scopeEmployeeIds->isEmpty()) {
                $query->whereRaw('1 = 0');
            } else {
                $this->responsibilityScope->applyInventoriesForEmployees(
                    $query,
                    'dashboard_pi',
                    $scopeEmployeeIds->all(),
                );
            }
        }

        return $query->get();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function topSellers(User $viewer, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        if (! $this->orderAuthorization->allows($viewer, OrderPermission::View)) {
            return collect();
        }

        $orders = Order::query()->select('orders.id')->where('orders.status', '!=', 'cancelled');
        if ($this->orderScope->requiresScope($viewer)) {
            $orders->whereHas('items');
        }
        $this->orderScope->applyOrders($orders, $viewer);

        return DB::table('order_fulfillment_items as dashboard_fulfillment_items')
            ->join('order_fulfillments as dashboard_fulfillments', 'dashboard_fulfillments.id', '=', 'dashboard_fulfillment_items.order_fulfillment_id')
            ->join('products as dashboard_sold_products', 'dashboard_sold_products.id', '=', 'dashboard_fulfillment_items.product_id')
            ->whereIn('dashboard_fulfillments.order_id', $orders)
            ->whereBetween('dashboard_fulfillments.fulfilled_at', [$from->startOfDay(), $to->endOfDay()])
            ->groupBy('dashboard_sold_products.id', 'dashboard_sold_products.sku', 'dashboard_sold_products.name', 'dashboard_sold_products.brand', 'dashboard_sold_products.model')
            ->select(['dashboard_sold_products.id as product_id', 'dashboard_sold_products.sku', 'dashboard_sold_products.name', 'dashboard_sold_products.brand', 'dashboard_sold_products.model'])
            ->selectRaw('SUM(dashboard_fulfillment_items.quantity) AS sold_quantity')
            ->orderByDesc('sold_quantity')
            ->limit(10)
            ->get()
            ->map(fn (object $row): array => [
                'product_id' => (int) $row->product_id,
                'product' => $row->name,
                'product_label' => $this->productLabel($row->brand, $row->model, $row->name),
                'sku' => $row->sku,
                'sold_quantity' => (int) $row->sold_quantity,
                'url' => OrderResource::getUrl(),
            ]);
    }

    private function productLabel(?string $brand, ?string $model, string $name): string
    {
        $identity = collect([$brand, $model])
            ->filter(fn (?string $value): bool => filled($value))
            ->map(fn (string $value): string => trim($value))
            ->unique(fn (string $value): string => mb_strtolower($value))
            ->implode(' · ');

        return $identity !== '' ? $identity : $name;
    }
}
