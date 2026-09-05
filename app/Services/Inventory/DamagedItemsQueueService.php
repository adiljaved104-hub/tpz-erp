<?php

namespace App\Services\Inventory;

use App\Enums\DamagedStockSource;
use App\Enums\DamagedStockStatus;
use App\Models\User;
use App\Services\Orders\OrderResponsibilityScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class DamagedItemsQueueService
{
    public function __construct(private readonly DamagedStockAvailabilityService $availability) {}

    /** @param array{search?:?string,warehouse_id?:?int,source?:?string,platform_id?:?int,status?:?string} $filters */
    public function paginate(User $user, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $paginator = $this->query($user, $filters)->orderByDesc('occurred_at')->orderBy('product_name')->paginate($perPage);
        $paginator->setCollection($paginator->getCollection()->map(fn (object $row): array => $this->present($row)));

        return $paginator;
    }

    /** @param array{search?:?string,warehouse_id?:?int,source?:?string,platform_id?:?int,status?:?string} $filters
     * @return array{items:int,units:int,oldest:?string}
     */
    public function summary(User $user, array $filters): array
    {
        $row = DB::query()->fromSub($this->query($user, $filters), 'damaged_items')
            ->selectRaw('COUNT(*) AS item_count, COALESCE(SUM(quantity), 0) AS unit_count, MIN(occurred_at) AS oldest_at')
            ->first();

        return [
            'items' => (int) $row->item_count,
            'units' => (int) $row->unit_count,
            'oldest' => $row->oldest_at === null
                ? null
                : CarbonImmutable::parse($row->oldest_at)->timezone(config('app.timezone'))->diffForHumans(),
        ];
    }

    /** @return array{warehouses: array<int, string>, platforms: array<int, string>} */
    public function filterOptions(User $user): array
    {
        $base = fn (): Builder => DB::query()->fromSub($this->query($user), 'damaged_filter_options');

        return [
            'warehouses' => $base()->whereNotNull('warehouse_id')->distinct()->orderBy('location')->pluck('location', 'warehouse_id')->all(),
            'platforms' => $base()->whereNotNull('marketplace_platform_id')->distinct()->orderBy('platform')->pluck('platform', 'marketplace_platform_id')->all(),
        ];
    }

    /**
     * Operational projection only: deliberately excludes average cost, inventory
     * value, COGS, profit, claim values, and all other financial fields.
     *
     * @param  array{search?:?string,warehouse_id?:?int,source?:?string,platform_id?:?int,status?:?string}  $filters
     */
    public function query(User $user, array $filters = []): Builder
    {
        $eventAvailability = $this->availability->eventAvailabilityQuery();
        $events = DB::query()->fromSub(clone $eventAvailability, 'dse')
            ->join('products as product', 'product.id', '=', 'dse.product_id')
            ->join('warehouses as warehouse', 'warehouse.id', '=', 'dse.warehouse_id')
            ->leftJoin('marketplace_platforms as platform', 'platform.id', '=', 'dse.marketplace_platform_id')
            ->leftJoin('customer_returns as customer_return', 'customer_return.id', '=', 'dse.customer_return_id')
            ->leftJoin('orders as orders', 'orders.id', '=', 'dse.order_id')
            ->leftJoin('users as reporter', 'reporter.id', '=', 'dse.reported_by_user_id')
            ->select(['dse.id', 'dse.reference', 'product.sku', 'product.name as product_name'])
            ->selectRaw('dse.remaining_damaged_quantity AS quantity')
            ->addSelect([
                'warehouse.name as location', 'dse.source', 'platform.name as platform',
                'customer_return.reference as return_reference', 'orders.reference as order_reference',
                'dse.reason', 'dse.notes', 'dse.occurred_at', 'reporter.name as reported_by',
            ])
            ->selectRaw("CASE WHEN dse.remaining_damaged_quantity > 0 THEN 'damaged' ELSE 'resolved' END AS status")
            ->addSelect(['dse.product_id', 'dse.warehouse_id', 'dse.marketplace_platform_id'])
            ->selectRaw("'event' AS row_kind")
            ->selectRaw('dse.available_repair_quantity');
        $this->applyResponsibilityScope($events, $user, 'dse.product_id', 'product.brand_id', 'dse.marketplace_platform_id', 'dse.warehouse_id');

        $classified = DB::query()->fromSub(clone $eventAvailability, 'classified_event')
            ->where('remaining_damaged_quantity', '>', 0)
            ->selectRaw('product_inventory_id, SUM(remaining_damaged_quantity) AS classified_quantity')
            ->groupBy('product_inventory_id');
        $legacy = DB::table('product_inventories as inventory')
            ->join('products as product', 'product.id', '=', 'inventory.product_id')
            ->join('warehouses as warehouse', 'warehouse.id', '=', 'inventory.warehouse_id')
            ->leftJoinSub($classified, 'classified', 'classified.product_inventory_id', '=', 'inventory.id')
            ->leftJoinSub($this->availability->activeLegacyRepairQuantitiesQuery(), 'legacy_repair', 'legacy_repair.product_inventory_id', '=', 'inventory.id')
            ->whereRaw('inventory.damaged_quantity > COALESCE(classified.classified_quantity, 0) + COALESCE(legacy_repair.active_legacy_repair_quantity, 0)')
            ->selectRaw('-inventory.id AS id, NULL AS reference, product.sku, product.name AS product_name')
            ->selectRaw('inventory.damaged_quantity - COALESCE(classified.classified_quantity, 0) - COALESCE(legacy_repair.active_legacy_repair_quantity, 0) AS quantity')
            ->addSelect(['warehouse.name as location'])
            ->selectRaw("'legacy' AS source, NULL AS platform, NULL AS return_reference, NULL AS order_reference")
            ->selectRaw("'Existing damaged balance without recorded history' AS reason, NULL AS notes, NULL AS occurred_at, NULL AS reported_by, 'damaged' AS status")
            ->addSelect(['inventory.product_id', 'inventory.warehouse_id'])
            ->selectRaw("NULL AS marketplace_platform_id, 'legacy' AS row_kind, inventory.damaged_quantity - COALESCE(classified.classified_quantity, 0) - COALESCE(legacy_repair.active_legacy_repair_quantity, 0) AS available_repair_quantity");
        $this->applyResponsibilityScope($legacy, $user, 'inventory.product_id', 'product.brand_id', null, 'inventory.warehouse_id');

        $query = DB::query()->fromSub($events->unionAll($legacy), 'damaged_queue');
        $search = trim((string) ($filters['search'] ?? ''));

        return $query
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $searchQuery) => $searchQuery
                ->where('sku', 'like', "%{$search}%")
                ->orWhere('product_name', 'like', "%{$search}%")))
            ->when($filters['warehouse_id'] ?? null, fn (Builder $q, int $id) => $q->where('warehouse_id', $id))
            ->when($filters['source'] ?? null, fn (Builder $q, string $source) => $q->where('source', $source))
            ->when($filters['platform_id'] ?? null, fn (Builder $q, int $id) => $q->where('marketplace_platform_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status));
    }

    private function applyResponsibilityScope(Builder $query, User $user, string $productColumn, string $brandColumn, ?string $platformColumn, string $warehouseColumn): void
    {
        if (! app(OrderResponsibilityScopeService::class)->requiresScope($user)) {
            return;
        }
        $employeeId = $user->employee?->id;
        if ($employeeId === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereExists(function (Builder $assignment) use ($employeeId, $productColumn, $brandColumn, $platformColumn, $warehouseColumn): void {
            $assignment->selectRaw('1')->from('responsibility_assignments as damage_ra')
                ->where('damage_ra.employee_id', $employeeId)->where('damage_ra.status', 'active')->whereNull('damage_ra.ended_at')
                ->where(function (Builder $platform) use ($platformColumn): void {
                    $platform->whereNotExists(fn (Builder $scope) => $scope->selectRaw('1')->from('responsibility_assignment_platforms as damage_platform_scope')->whereColumn('damage_platform_scope.assignment_id', 'damage_ra.id'));
                    if ($platformColumn !== null) {
                        $platform->orWhereExists(fn (Builder $scope) => $scope->selectRaw('1')->from('responsibility_assignment_platforms as damage_platform_match')->whereColumn('damage_platform_match.assignment_id', 'damage_ra.id')->whereColumn('damage_platform_match.marketplace_platform_id', $platformColumn));
                    }
                })
                ->where(function (Builder $product) use ($productColumn, $brandColumn, $warehouseColumn): void {
                    $product->whereExists(fn (Builder $scope) => $scope->selectRaw('1')->from('responsibility_assignment_products as damage_product_scope')->whereColumn('damage_product_scope.assignment_id', 'damage_ra.id')->whereColumn('damage_product_scope.product_id', $productColumn))
                        ->orWhereExists(fn (Builder $scope) => $scope->selectRaw('1')->from('responsibility_assignment_brands as damage_brand_scope')->whereColumn('damage_brand_scope.assignment_id', 'damage_ra.id')->whereColumn('damage_brand_scope.product_brand_id', $brandColumn))
                        ->orWhereExists(fn (Builder $scope) => $scope->selectRaw('1')->from('inventory_responsibility_quantities as damage_quantity_scope')->join('product_inventories as damage_quantity_inventory', 'damage_quantity_inventory.id', '=', 'damage_quantity_scope.product_inventory_id')->whereColumn('damage_quantity_scope.assignment_id', 'damage_ra.id')->whereColumn('damage_quantity_inventory.product_id', $productColumn)->whereColumn('damage_quantity_inventory.warehouse_id', $warehouseColumn));
                });
        });
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        $occurredAt = $row->occurred_at === null ? null : CarbonImmutable::parse($row->occurred_at)->timezone(config('app.timezone'));

        return (array) $row + [
            'source_label' => $row->source === 'legacy' ? 'Old Damaged Stock' : DamagedStockSource::from($row->source)->getLabel(),
            'status_label' => DamagedStockStatus::from($row->status)->getLabel(),
            'damaged_date' => $occurredAt?->format('d M Y, h:i A'),
            'days_damaged' => $occurredAt?->startOfDay()->diffInDays(now(config('app.timezone'))->startOfDay()),
            'related_reference' => $row->return_reference ?? $row->order_reference,
        ];
    }
}
