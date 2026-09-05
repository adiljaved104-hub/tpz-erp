<?php

namespace App\Services\Inventory;

use App\Enums\InventoryPermission;
use App\Models\ProductInventory;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InventoryLocationOverviewService
{
    public function __construct(
        private readonly InventoryAuthorization $authorization,
        private readonly ResponsibilityProductScopeService $responsibilities,
    ) {}

    /** @return Collection<int, array<string, mixed>> */
    public function forUser(User $user, bool $includeFinancial = true): Collection
    {
        $financial = $includeFinancial && $this->authorization->allows($user, InventoryPermission::ViewFinancials);
        $columns = [
            'id', 'product_id', 'warehouse_id', 'available_quantity', 'reserved_quantity', 'damaged_quantity',
            'marketplace_non_sellable_quantity', 'qc_pending_quantity',
        ];

        if ($financial) {
            array_push($columns, 'average_cost', 'marketplace_non_sellable_value', 'qc_pending_value');
        }

        $inventoryQuery = ProductInventory::query()
            ->select($columns)
            ->with([
                'product:id,sku,name',
                'warehouse:id,name,code,location_type,marketplace_platform_id,fulfillment_tag,status',
                'warehouse.marketplacePlatform:id,name',
            ])
            ->orderBy('product_id')
            ->orderBy('warehouse_id');
        $this->responsibilities->applyInventories($inventoryQuery->getQuery(), 'product_inventories', $user);
        $inventories = $inventoryQuery->get();

        $transit = collect();
        if (Schema::hasTable('stock_transfer_items')) {
            $columns = ['stock_transfer_items.product_id', 'stock_transfer_items.dispatched_quantity', 'stock_transfer_items.received_quantity', 'stock_transfer_items.returned_quantity', 'stock_transfer_items.lost_quantity'];
            if ($financial) {
                $columns[] = 'stock_transfer_items.dispatch_unit_cost';
            }
            $transit = DB::table('stock_transfer_items')->join('stock_transfers', 'stock_transfers.id', '=', 'stock_transfer_items.stock_transfer_id')
                ->whereIn('stock_transfer_items.source_product_inventory_id', $this->responsibilities->inventoryIds($user))
                ->where('stock_transfers.status', 'dispatched')->get($columns)->groupBy('product_id');
        }
        $returnTransit = collect();
        if (Schema::hasTable('marketplace_return_removal_items')) {
            $returnColumns = ['marketplace_return_removal_items.product_id', 'marketplace_return_removal_items.dispatched_quantity', 'marketplace_return_removal_items.received_quantity'];
            if ($financial) {
                $returnColumns[] = 'marketplace_return_removal_items.unit_cost';
            }
            $returnTransit = DB::table('marketplace_return_removal_items')->join('marketplace_return_removals', 'marketplace_return_removals.id', '=', 'marketplace_return_removal_items.marketplace_return_removal_id')
                ->whereIn('marketplace_return_removal_items.source_product_inventory_id', $this->responsibilities->inventoryIds($user))
                ->where('marketplace_return_removals.status', 'dispatched')->get($returnColumns)->groupBy('product_id');
        }

        return $inventories->groupBy('product_id')->map(function (Collection $rows) use ($financial, $transit, $returnTransit): array {
            $product = $rows->first()->product;
            $locations = $rows->map(function (ProductInventory $inventory) use ($financial): array {
                $available = (int) $inventory->available_quantity;
                $reserved = (int) $inventory->reserved_quantity;
                $damaged = (int) $inventory->damaged_quantity;
                $marketplaceNonSellable = (int) $inventory->marketplace_non_sellable_quantity;
                $qcPending = (int) $inventory->qc_pending_quantity;
                $location = [
                    'name' => $inventory->warehouse->name,
                    'code' => $inventory->warehouse->code,
                    'type' => $inventory->warehouse->location_type->getLabel(),
                    'platform' => $inventory->warehouse->marketplacePlatform?->name,
                    'fulfillment_tag' => $inventory->warehouse->fulfillment_tag,
                    'active' => (bool) $inventory->warehouse->status,
                    'available' => $available,
                    'reserved' => $reserved,
                    'sellable' => $available - $reserved,
                    'damaged' => $damaged,
                    'total_on_hand' => $available + $damaged,
                    'marketplace_non_sellable' => $marketplaceNonSellable,
                    'qc_pending' => $qcPending,
                    'location_total_owned' => $available + $damaged + $marketplaceNonSellable + $qcPending,
                ];

                if ($financial) {
                    $location['average_cost'] = $inventory->average_cost;
                    $location['pooled_value'] = bcmul((string) ($inventory->average_cost ?? '0'), (string) ($available + $damaged), 4);
                    $location['marketplace_non_sellable_value'] = (string) $inventory->marketplace_non_sellable_value;
                    $location['qc_pending_value'] = (string) $inventory->qc_pending_value;
                    $location['inventory_value'] = bcadd(
                        bcadd($location['pooled_value'], $location['marketplace_non_sellable_value'], 4),
                        $location['qc_pending_value'],
                        4,
                    );
                }

                return $location;
            });

            $transitRows = $transit->get($product->id, collect());
            $inTransit = $transitRows->sum(fn ($item): int => (int) $item->dispatched_quantity - (int) $item->received_quantity - (int) $item->returned_quantity - (int) $item->lost_quantity);
            $returnTransitRows = $returnTransit->get($product->id, collect());
            $returnInTransit = $returnTransitRows->sum(fn ($item): int => (int) $item->dispatched_quantity - (int) $item->received_quantity);
            $result = [
                'product' => $product,
                'available' => $locations->sum('available'),
                'reserved' => $locations->sum('reserved'),
                'sellable' => $locations->sum('sellable'),
                'damaged' => $locations->sum('damaged'),
                'marketplace_non_sellable' => $locations->sum('marketplace_non_sellable'),
                'qc_pending' => $locations->sum('qc_pending'),
                'total_on_hand' => $locations->sum('total_on_hand'),
                'in_transit' => $inTransit,
                'return_in_transit' => $returnInTransit,
                'total_owned' => $locations->sum('location_total_owned') + $inTransit + $returnInTransit,
                'locations' => $locations,
            ];

            if ($financial) {
                $result['inventory_value'] = $locations->reduce(
                    fn (string $carry, array $location): string => bcadd($carry, $location['inventory_value'], 4),
                    '0.0000',
                );
                $result['in_transit_value'] = $transitRows->reduce(function (string $carry, $item): string {
                    $quantity = (int) $item->dispatched_quantity - (int) $item->received_quantity - (int) $item->returned_quantity - (int) $item->lost_quantity;

                    return bcadd($carry, bcmul((string) ($item->dispatch_unit_cost ?? '0'), (string) $quantity, 4), 4);
                }, '0.0000');
                $result['total_inventory_value'] = bcadd($result['inventory_value'], $result['in_transit_value'], 4);
                $result['return_in_transit_value'] = $returnTransitRows->reduce(fn (string $carry, $item): string => bcadd($carry, bcmul((string) $item->unit_cost, (string) ((int) $item->dispatched_quantity - (int) $item->received_quantity), 4), 4), '0.0000');
                $result['total_inventory_value'] = bcadd($result['total_inventory_value'], $result['return_in_transit_value'], 4);
            }

            return $result;
        })->values();
    }

    public function summaryForUser(User $user): array
    {
        return $this->summaryForProducts($this->forUser($user));
    }

    /** @param Collection<int, array<string, mixed>> $products */
    public function summaryForProducts(Collection $products): array
    {
        return [
            'total_company_stock' => $products->sum('total_owned'),
            'on_location' => $products->sum(fn (array $product): int => $product['total_owned'] - $product['in_transit'] - $product['return_in_transit']),
            'in_transit' => $products->sum('in_transit'),
            'return_in_transit' => $products->sum('return_in_transit'),
            'damaged' => $products->sum('damaged'),
            'marketplace_non_sellable' => $products->sum('marketplace_non_sellable'),
            'qc_pending' => $products->sum('qc_pending'),
        ];
    }
}
