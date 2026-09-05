<?php

namespace App\Services\Purchases;

use App\DTOs\Purchases\PurchaseProductContext;
use App\Enums\InventoryPermission;
use App\Enums\ProductPermission;
use App\Enums\PurchasePermission;
use App\Enums\PurchaseStatus;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\ProductAuthorization;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\Inventory\WeightedAverageCostCalculator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PurchaseProductContextService
{
    public function __construct(
        private readonly PurchaseAuthorization $purchases,
        private readonly InventoryAuthorization $inventoryAuthorization,
        private readonly ProductAuthorization $productAuthorization,
        private readonly WeightedAverageCostCalculator $costs,
        private readonly PurchaseTotalsCalculator $decimals,
    ) {}

    /** @param array<int, int> $productIds
     * @return array<int, PurchaseProductContext>
     */
    public function forProducts(User $actor, int $warehouseId, array $productIds, ?Purchase $purchase = null): array
    {
        $this->purchases->authorize($actor, PurchasePermission::View, $purchase);

        return $this->contexts($actor, $warehouseId, $productIds, $purchase);
    }

    /** @param array<int, int> $productIds
     * @return array<int, PurchaseProductContext>
     */
    public function forQuickStockPurchase(User $actor, int $warehouseId, array $productIds): array
    {
        $this->purchases->authorize($actor, PurchasePermission::QuickReceive);

        return $this->contexts($actor, $warehouseId, $productIds);
    }

    /** @param array<int, int> $productIds
     * @return array<int, PurchaseProductContext>
     */
    private function contexts(User $actor, int $warehouseId, array $productIds, ?Purchase $purchase = null): array
    {
        $productIds = collect($productIds)->map(fn ($id): int => (int) $id)->unique()->values();

        if ($productIds->isEmpty()) {
            return [];
        }

        $inventoryFinancials = $this->inventoryAuthorization->allows($actor, InventoryPermission::ViewFinancials);
        $productCost = $this->productAuthorization->allows($actor, ProductPermission::ViewCostPrice);
        $historyAllowed = $this->purchases->allows($actor, PurchasePermission::ViewCostHistory, $purchase);
        $inventoryFields = ['product_id', 'available_quantity', 'reserved_quantity', 'damaged_quantity'];

        if ($inventoryFinancials) {
            $inventoryFields[] = 'average_cost';
        }

        $inventories = DB::table('product_inventories')->select($inventoryFields)
            ->where('warehouse_id', $warehouseId)->whereIn('product_id', $productIds)->get()->keyBy('product_id');
        $outstanding = DB::table('purchase_items as pi')->join('purchases as p', 'p.id', '=', 'pi.purchase_id')
            ->selectRaw('pi.product_id, SUM(pi.ordered_quantity - pi.received_quantity) AS outstanding_quantity')
            ->where('p.warehouse_id', $warehouseId)
            ->whereIn('pi.product_id', $productIds)
            ->whereIn('p.status', [PurchaseStatus::Approved->value, PurchaseStatus::PartiallyReceived->value])
            ->when($purchase, fn ($query) => $query->where('p.id', '<>', $purchase->id))
            ->groupBy('pi.product_id')->pluck('outstanding_quantity', 'pi.product_id');
        $products = Product::query()->select($productCost ? ['id', 'cost_price'] : ['id'])->whereIn('id', $productIds)->get()->keyBy('id');
        $histories = $historyAllowed ? $this->historyRows($warehouseId, $productIds->all()) : collect();
        $groupedHistory = $histories->groupBy('product_id');
        $result = [];

        foreach ($productIds as $productId) {
            $balance = $inventories->get($productId);
            $rows = $groupedHistory->get($productId, collect());
            $latest = $rows->first();
            $weighted = $this->weighted($rows);
            $average = $inventoryFinancials && $balance?->average_cost !== null
                ? $this->costs->normalize((string) $balance->average_cost)
                : null;
            $onHand = (int) ($balance?->available_quantity ?? 0) + (int) ($balance?->damaged_quantity ?? 0);
            $result[$productId] = new PurchaseProductContext(
                productId: $productId,
                availableQuantity: (int) ($balance?->available_quantity ?? 0),
                reservedQuantity: (int) ($balance?->reserved_quantity ?? 0),
                damagedQuantity: (int) ($balance?->damaged_quantity ?? 0),
                outstandingOnOtherPurchases: (int) ($outstanding[$productId] ?? 0),
                latestReceivedCost: $latest?->inventory_unit_cost,
                weightedReceivedCost: $weighted,
                lowestReceivedCost: $this->extreme($rows, lowest: true),
                highestReceivedCost: $this->extreme($rows, lowest: false),
                recentReceipts: $rows->take(5)->map(fn ($row): array => (array) $row)->all(),
                inventoryAverageCost: $average,
                inventoryValue: $average === null ? null : $this->costs->inventoryValue($onHand, $average),
                productCostPrice: $productCost ? $products->get($productId)?->cost_price : null,
            );
        }

        return $result;
    }

    /** @param array<int, int> $productIds */
    public function historyRows(int $warehouseId, array $productIds): Collection
    {
        return DB::table('purchase_receipt_items as pri')
            ->join('purchase_receipts as pr', 'pr.id', '=', 'pri.purchase_receipt_id')
            ->join('purchases as p', 'p.id', '=', 'pr.purchase_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'p.supplier_id')
            ->join('products as product', 'product.id', '=', 'pri.product_id')
            ->select([
                'pri.id', 'pri.product_id', 'pri.accepted_quantity', 'pri.damaged_quantity', 'pri.inventory_unit_cost',
                'pr.received_at', 'pr.reference as grn_reference', 'p.reference as purchase_reference',
                'p.supplier_id', 'p.warehouse_id', DB::raw("COALESCE(s.name, 'No Supplier') as supplier_name"), 'product.name as product_name', 'product.sku',
            ])
            ->where('p.warehouse_id', $warehouseId)
            ->whereIn('pri.product_id', $productIds)
            ->whereNotIn('p.status', [PurchaseStatus::Draft->value, PurchaseStatus::Cancelled->value])
            ->whereRaw('(pri.accepted_quantity + pri.damaged_quantity) > 0')
            ->orderByDesc('pr.received_at')->orderByDesc('pr.id')->orderByDesc('pri.id')->get();
    }

    private function weighted(Collection $rows): ?string
    {
        $weightedSum = '0.0000';
        $quantity = 0;

        foreach ($rows as $row) {
            $lineQuantity = (int) $row->accepted_quantity + (int) $row->damaged_quantity;
            $weightedSum = bcadd($weightedSum, bcmul((string) $lineQuantity, (string) $row->inventory_unit_cost, 4), 4);
            $quantity += $lineQuantity;
        }

        return $quantity === 0 ? null : $this->decimals->divideHalfUp($weightedSum, (string) $quantity, 4);
    }

    private function extreme(Collection $rows, bool $lowest): ?string
    {
        return $rows->reduce(function (?string $carry, $row) use ($lowest): string {
            $value = $this->costs->normalize((string) $row->inventory_unit_cost);

            if ($carry === null || ($lowest ? bccomp($value, $carry, 4) < 0 : bccomp($value, $carry, 4) > 0)) {
                return $value;
            }

            return $carry;
        });
    }
}
