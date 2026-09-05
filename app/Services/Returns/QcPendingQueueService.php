<?php

namespace App\Services\Returns;

use App\Enums\CustomerReturnReason;
use App\Enums\MarketplaceReturnPermission;
use App\Models\MarketplaceReturnRemoval;
use App\Models\User;
use App\Services\Authorization\MarketplaceReturnAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class QcPendingQueueService
{
    /**
     * @param  array{search?:?string,warehouse_id?:?int,platform_id?:?int,return_reason?:?string,received_from?:?string,min_days_pending?:?int}  $filters
     */
    public function paginate(User $user, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $paginator = $this->query($user, $filters)
            ->orderBy('received_at')
            ->orderBy('id')
            ->paginate($perPage);

        $paginator->setCollection($paginator->getCollection()->map(fn (object $row): array => $this->present($row)));

        return $paginator;
    }

    /**
     * @param  array{search?:?string,warehouse_id?:?int,platform_id?:?int,return_reason?:?string,received_from?:?string,min_days_pending?:?int}  $filters
     * @return array{returns:int,units:int,oldest:?string}
     */
    public function summary(User $user, array $filters): array
    {
        $query = $this->query($user, $filters);
        $row = DB::query()->fromSub($query, 'pending_qc')->selectRaw(
            'COUNT(DISTINCT subject_key) AS return_count, COALESCE(SUM(pending_quantity), 0) AS unit_count, MIN(received_at) AS oldest_received_at',
        )->first();

        return [
            'returns' => (int) $row->return_count,
            'units' => (int) $row->unit_count,
            'oldest' => $row->oldest_received_at === null
                ? null
                : CarbonImmutable::parse($row->oldest_received_at)->timezone(config('app.timezone'))->diffForHumans(),
        ];
    }

    /** @return array{warehouses: array<int, string>, platforms: array<int, string>} */
    public function filterOptions(User $user): array
    {
        $base = fn (): Builder => DB::query()->fromSub($this->query($user), 'qc_filter_options');

        return [
            'warehouses' => $base()->whereNotNull('receiving_warehouse_id')->distinct()->orderBy('receiving_location')->pluck('receiving_location', 'receiving_warehouse_id')->all(),
            'platforms' => $base()->whereNotNull('platform_id')->distinct()->orderBy('platform')->pluck('platform', 'platform_id')->all(),
        ];
    }

    /**
     * This projection deliberately omits inventory_unit_cost, average_cost,
     * qc_pending_value, COGS, profit, and inventory value.
     *
     * @param  array{search?:?string,warehouse_id?:?int,platform_id?:?int,return_reason?:?string,received_from?:?string,min_days_pending?:?int}  $filters
     */
    public function query(User $user, array $filters = []): Builder
    {
        $directInspections = DB::table('customer_return_inspections')->whereNotNull('customer_return_item_id')
            ->select(['customer_return_item_id', 'quantity']);
        $removalInspections = DB::table('customer_return_inspections as removal_inspection')
            ->join('marketplace_return_removal_items as inspected_removal_item', 'inspected_removal_item.id', '=', 'removal_inspection.marketplace_return_removal_item_id')
            ->whereNotNull('inspected_removal_item.customer_return_item_id')
            ->select(['inspected_removal_item.customer_return_item_id', 'removal_inspection.quantity']);
        $inspected = DB::query()->fromSub($directInspections->unionAll($removalInspections), 'return_inspections')
            ->selectRaw('customer_return_item_id, SUM(quantity) AS inspected_quantity')->groupBy('customer_return_item_id');
        $companyReceived = DB::table('marketplace_return_removal_items')
            ->selectRaw('customer_return_item_id, SUM(received_quantity) AS received_quantity')
            ->groupBy('customer_return_item_id');
        $marketplaceDispositioned = DB::table('customer_return_marketplace_dispositions')
            ->selectRaw('customer_return_item_id, SUM(quantity) AS dispositioned_quantity')
            ->groupBy('customer_return_item_id');

        $returns = DB::table('customer_return_items as cri')
            ->join('customer_returns as cr', 'cr.id', '=', 'cri.customer_return_id')
            ->join('orders as orders', 'orders.id', '=', 'cr.order_id')
            ->join('products as product', 'product.id', '=', 'cri.product_id')
            ->leftJoin('marketplace_platforms as platform', 'platform.id', '=', 'cr.marketplace_platform_id')
            ->join('warehouses as fulfilled_from', 'fulfilled_from.id', '=', 'cr.fulfillment_warehouse_id')
            ->join('warehouses as receiving', 'receiving.id', '=', DB::raw('COALESCE(cri.company_receiving_warehouse_id, cr.receiving_warehouse_id)'))
            ->leftJoin('users as receiver', 'receiver.id', '=', 'cr.received_by_user_id')
            ->leftJoinSub($inspected, 'inspection_totals', 'inspection_totals.customer_return_item_id', '=', 'cri.id')
            ->leftJoinSub($companyReceived, 'company_received', 'company_received.customer_return_item_id', '=', 'cri.id')
            ->leftJoinSub($marketplaceDispositioned, 'marketplace_dispositioned', 'marketplace_dispositioned.customer_return_item_id', '=', 'cri.id')
            ->whereIn('cr.id', app(CustomerReturnReadService::class)->query($user)->select('customer_returns.id'))
            ->whereNotNull('cr.received_at')
            ->whereRaw('COALESCE(marketplace_dispositioned.dispositioned_quantity, 0) = 0')
            ->whereRaw('(CASE WHEN COALESCE(marketplace_dispositioned.dispositioned_quantity, 0) > 0 THEN COALESCE(company_received.received_quantity, 0) ELSE cri.return_quantity END) > COALESCE(inspection_totals.inspected_quantity, 0)')
            ->select([
                'cri.id', 'cri.customer_return_id', 'cr.reference as return_reference', 'orders.reference as order_reference',
                'cri.sku_snapshot as sku', 'cri.product_name_snapshot as product_name',
                'cri.return_reason', 'platform.name as platform', 'fulfilled_from.name as fulfilled_from',
                'receiving.name as receiving_location', 'cr.received_at', 'receiver.name as received_by',
            ])
            ->selectRaw("'customer_return' AS source_kind, cri.customer_return_id AS source_id, cr.reference AS subject_key, COALESCE(cri.company_receiving_warehouse_id, cr.receiving_warehouse_id) AS receiving_warehouse_id, cr.marketplace_platform_id AS platform_id")
            ->selectRaw('COALESCE(inspection_totals.inspected_quantity, 0) AS inspected_quantity')
            ->selectRaw('(CASE WHEN COALESCE(marketplace_dispositioned.dispositioned_quantity, 0) > 0 THEN COALESCE(company_received.received_quantity, 0) ELSE cri.return_quantity END) AS received_quantity')
            ->selectRaw('(CASE WHEN COALESCE(marketplace_dispositioned.dispositioned_quantity, 0) > 0 THEN COALESCE(company_received.received_quantity, 0) ELSE cri.return_quantity END) - COALESCE(inspection_totals.inspected_quantity, 0) AS pending_quantity');

        $removalInspected = DB::table('customer_return_inspections')->whereNotNull('marketplace_return_removal_item_id')
            ->selectRaw('marketplace_return_removal_item_id, SUM(quantity) AS inspected_quantity')->groupBy('marketplace_return_removal_item_id');
        $authorizedRemovalIds = app(MarketplaceReturnAuthorization::class)->scopeQuery(MarketplaceReturnRemoval::query(), $user)->select('marketplace_return_removals.id');
        $removals = DB::table('marketplace_return_removal_items as mrri')
            ->join('marketplace_return_removals as mrr', 'mrr.id', '=', 'mrri.marketplace_return_removal_id')
            ->join('products as product', 'product.id', '=', 'mrri.product_id')
            ->join('marketplace_platforms as platform', 'platform.id', '=', 'mrr.marketplace_platform_id')
            ->join('warehouses as fulfilled_from', 'fulfilled_from.id', '=', 'mrr.source_warehouse_id')
            ->join('warehouses as receiving', 'receiving.id', '=', 'mrr.destination_warehouse_id')
            ->leftJoin('users as receiver', 'receiver.id', '=', 'mrr.received_by_user_id')
            ->leftJoinSub($removalInspected, 'inspection_totals', 'inspection_totals.marketplace_return_removal_item_id', '=', 'mrri.id')
            ->whereIn('mrr.id', $authorizedRemovalIds)
            ->where('mrr.status', 'received')
            ->whereRaw('mrri.received_quantity > COALESCE(inspection_totals.inspected_quantity, 0)')
            ->selectRaw('mrri.id, NULL AS customer_return_id, mrr.reference AS return_reference, NULL AS order_reference')
            ->addSelect(['product.sku as sku', 'product.name as product_name'])
            ->selectRaw('NULL AS return_reason')
            ->addSelect(['platform.name as platform', 'fulfilled_from.name as fulfilled_from', 'receiving.name as receiving_location', 'mrr.received_at', 'receiver.name as received_by'])
            ->selectRaw("'marketplace_removal' AS source_kind, mrr.id AS source_id, mrr.reference AS subject_key, mrr.destination_warehouse_id AS receiving_warehouse_id, mrr.marketplace_platform_id AS platform_id")
            ->selectRaw('COALESCE(inspection_totals.inspected_quantity, 0) AS inspected_quantity, mrri.received_quantity AS received_quantity')
            ->selectRaw('mrri.received_quantity - COALESCE(inspection_totals.inspected_quantity, 0) AS pending_quantity');

        if (! app(MarketplaceReturnAuthorization::class)->allows($user, MarketplaceReturnPermission::View)) {
            $removals->whereRaw('1 = 0');
        }

        $search = trim((string) ($filters['search'] ?? ''));

        return DB::query()->fromSub($returns->unionAll($removals), 'qc')
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $searchQuery) => $searchQuery
                ->where('sku', 'like', "%{$search}%")
                ->orWhere('product_name', 'like', "%{$search}%")))
            ->when($filters['warehouse_id'] ?? null, fn (Builder $q, int $id) => $q->where('receiving_warehouse_id', $id))
            ->when($filters['platform_id'] ?? null, fn (Builder $q, int $id) => $q->where('platform_id', $id))
            ->when($filters['return_reason'] ?? null, fn (Builder $q, string $reason) => $q->where('return_reason', $reason))
            ->when($filters['received_from'] ?? null, fn (Builder $q, string $date) => $q->whereDate('received_at', '>=', $date))
            ->when($filters['min_days_pending'] ?? null, fn (Builder $q, int $days) => $q->where('received_at', '<=', now()->subDays($days)));
    }

    /** @return array<string, int|string|null> */
    private function present(object $row): array
    {
        $receivedAt = CarbonImmutable::parse($row->received_at)->timezone(config('app.timezone'));

        return (array) $row + [
            'return_reason_label' => $row->return_reason === null ? 'Marketplace Removal' : CustomerReturnReason::from($row->return_reason)->label(),
            'received_at_display' => $receivedAt->format('d M Y, h:i A'),
            'days_pending' => $receivedAt->startOfDay()->diffInDays(now(config('app.timezone'))->startOfDay()),
        ];
    }
}
