<?php

namespace App\Services\Purchases;

use App\DTOs\Purchases\PurchaseCostHistoryFilterData;
use App\DTOs\Purchases\PurchaseCostHistorySummary;
use App\Enums\PurchasePermission;
use App\Models\Product;
use App\Models\User;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PurchaseCostHistoryService
{
    public function __construct(
        private readonly PurchaseAuthorization $authorization,
        private readonly PurchaseTotalsCalculator $decimals,
        private readonly ResponsibilityProductScopeService $responsibilityScope,
    ) {}

    public function query(PurchaseCostHistoryFilterData $filters, User $actor): Builder
    {
        $this->authorization->authorize($actor, PurchasePermission::ViewCostHistory);

        $query = DB::table('purchase_receipt_items as pri')
            ->join('purchase_receipts as pr', 'pr.id', '=', 'pri.purchase_receipt_id')
            ->join('purchases as p', 'p.id', '=', 'pr.purchase_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'p.supplier_id')
            ->join('products as product', 'product.id', '=', 'pri.product_id')
            ->join('warehouses as w', 'w.id', '=', 'p.warehouse_id')
            ->select([
                'product.name as product', 'product.sku', 'w.name as warehouse', 'pr.received_at',
                DB::raw("COALESCE(s.name, 'No Supplier') as supplier"), 'p.reference as purchase_reference', 'pr.reference as grn_reference',
                'pri.accepted_quantity', 'pri.damaged_quantity', 'pri.inventory_unit_cost',
            ])->selectRaw('(pri.accepted_quantity + pri.damaged_quantity) AS valuation_quantity')
            ->whereRaw('(pri.accepted_quantity + pri.damaged_quantity) > 0')
            ->whereNotIn('p.status', ['draft', 'cancelled']);

        $this->responsibilityScope->apply($query, 'pri.product_id', $actor);

        return $query
            ->when($filters->productId, fn ($query, $id) => $query->where('pri.product_id', $id))
            ->when($filters->supplierId === 'none', fn ($query) => $query->whereNull('p.supplier_id'))
            ->when($filters->supplierId && $filters->supplierId !== 'none', fn ($query, $id) => $query->where('p.supplier_id', $id))
            ->when($filters->warehouseId, fn ($query, $id) => $query->where('p.warehouse_id', $id))
            ->when($filters->dateFrom, fn ($query, $date) => $query->whereDate('pr.received_at', '>=', $date))
            ->when($filters->dateTo, fn ($query, $date) => $query->whereDate('pr.received_at', '<=', $date))
            ->orderByDesc('pr.received_at')->orderByDesc('pr.id')->orderByDesc('pri.id');
    }

    public function addLatestCostSelect(EloquentBuilder $query, string $qualifiedProductColumn, User $actor): EloquentBuilder
    {
        $this->authorization->authorize($actor, PurchasePermission::ViewCostHistory);

        $latestCost = DB::table('purchase_receipt_items as latest_pri')
            ->join('purchase_receipts as latest_pr', 'latest_pr.id', '=', 'latest_pri.purchase_receipt_id')
            ->join('purchases as latest_p', 'latest_p.id', '=', 'latest_pr.purchase_id')
            ->select('latest_pri.inventory_unit_cost')
            ->whereColumn('latest_pri.product_id', $qualifiedProductColumn)
            ->whereRaw('(latest_pri.accepted_quantity + latest_pri.damaged_quantity) > 0')
            ->whereNotIn('latest_p.status', ['draft', 'cancelled'])
            ->orderByDesc('latest_pr.received_at')
            ->orderByDesc('latest_pr.id')
            ->orderByDesc('latest_pri.id')
            ->limit(1);

        $this->responsibilityScope->apply($latestCost, 'latest_pri.product_id', $actor);

        return $query->addSelect(['latest_purchase_cost' => $latestCost]);
    }

    public function summaryForProduct(int $productId, User $actor): PurchaseCostHistorySummary
    {
        $this->authorization->authorize($actor, PurchasePermission::ViewCostHistory);

        $productQuery = Product::query()->select(['id', 'name', 'sku'])->whereKey($productId);
        $this->responsibilityScope->apply($productQuery->getQuery(), 'products.id', $actor);
        $product = $productQuery->firstOrFail();
        $rows = $this->query(new PurchaseCostHistoryFilterData(productId: $productId), $actor)
            ->select([
                'pr.received_at', 'pr.id as receipt_id', 'pri.id as receipt_item_id',
                'pri.inventory_unit_cost', 'p.reference as purchase_reference', 'pr.reference as grn_reference',
            ])
            ->selectRaw('(pri.accepted_quantity + pri.damaged_quantity) AS valuation_quantity')
            ->get();
        $latest = $rows->first();

        return new PurchaseCostHistorySummary(
            productId: $product->id,
            productName: $product->name,
            sku: $product->sku,
            latestReceivedCost: $latest === null ? null : $this->normalizeCost((string) $latest->inventory_unit_cost),
            latestReceiptDate: $latest === null ? null : CarbonImmutable::parse($latest->received_at),
            latestReceivedQuantity: $latest === null ? null : (int) $latest->valuation_quantity,
            recentEntries: $rows->take(5)->map(fn ($row): array => [
                'date' => CarbonImmutable::parse($row->received_at),
                'quantity' => (int) $row->valuation_quantity,
                'unit_cost' => $this->normalizeCost((string) $row->inventory_unit_cost),
                'purchase_reference' => (string) $row->purchase_reference,
                'grn_reference' => (string) $row->grn_reference,
            ])->all(),
            weightedAverageReceivedCost: $this->weightedAverage($rows),
        );
    }

    /** @return array<int, string> */
    public function supplierOptions(User $actor): array
    {
        return $this->query(new PurchaseCostHistoryFilterData, $actor)
            ->whereNotNull('p.supplier_id')
            ->select(['p.supplier_id', 's.name'])
            ->distinct()
            ->reorder('s.name')
            ->pluck('s.name', 'p.supplier_id')
            ->all();
    }

    private function weightedAverage(Collection $rows): ?string
    {
        $weightedSum = '0.0000';
        $quantity = 0;

        foreach ($rows as $row) {
            $valuationQuantity = (int) $row->valuation_quantity;
            $weightedSum = bcadd($weightedSum, bcmul((string) $valuationQuantity, (string) $row->inventory_unit_cost, 4), 4);
            $quantity += $valuationQuantity;
        }

        return $quantity === 0
            ? null
            : $this->decimals->divideHalfUp($weightedSum, (string) $quantity, 4);
    }

    private function normalizeCost(string $cost): string
    {
        return bcadd($cost, '0', 4);
    }
}
