<?php

namespace App\Services\Reports;

use App\Contracts\Reports\ReportHandler;
use App\DTOs\Reports\ReportDefinition;
use App\DTOs\Reports\ReportResult;
use App\Enums\ExpenseCategory;
use App\Enums\ExpenseCostCenter;
use App\Enums\HrPermission;
use App\Enums\InventoryPermission;
use App\Enums\OrderPermission;
use App\Enums\PurchasePermission;
use App\Enums\SafetClaimPermission;
use App\Enums\WebSalesChannel;
use App\Models\Complaint;
use App\Models\Order;
use App\Models\SafetClaim;
use App\Models\User;
use App\Models\WarrantyRepair;
use App\Services\Authorization\ComplaintAuthorization;
use App\Services\Authorization\HrAuthorization;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\PerformanceAuthorization;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\Authorization\SafetClaimAuthorization;
use App\Services\Authorization\WarrantyRepairAuthorization;
use App\Services\Dashboard\DashboardInventoryIntelligenceService;
use App\Services\Hr\AttendanceQueryService;
use App\Services\Hr\HrScopeService;
use App\Services\Orders\OrderResponsibilityScopeService;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use App\Services\Returns\CustomerReturnReadService;
use App\Services\Tasks\TaskQueryService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReportQueryService
{
    public const EXPORT_LIMIT = 10000;

    public const PREVIEW_LIMIT = 50;

    public function __construct(
        private readonly ReportCatalog $catalog,
        private readonly OrderAuthorization $orderAuth,
        private readonly OrderResponsibilityScopeService $orderScope,
        private readonly InventoryAuthorization $inventoryAuth,
        private readonly ResponsibilityProductScopeService $productScope,
        private readonly PurchaseAuthorization $purchaseAuth,
        private readonly CustomerReturnReadService $returnReads,
        private readonly SafetClaimAuthorization $claimAuth,
        private readonly WarrantyRepairAuthorization $warrantyAuth,
        private readonly ComplaintAuthorization $complaintAuth,
        private readonly TaskQueryService $taskQueries,
        private readonly AttendanceQueryService $attendance,
        private readonly HrScopeService $hrScope,
        private readonly HrAuthorization $hrAuth,
        private readonly PerformanceAuthorization $performanceAuth,
    ) {}

    /** @param array<string, mixed> $filters */
    public function run(User $user, string $key, array $filters, int $limit = self::PREVIEW_LIMIT): ReportResult
    {
        $definition = $this->catalog->resolve($user, $key);
        $filters = $this->normalizeFilters($filters);

        if ($definition->handler !== self::class) {
            $handler = app($definition->handler);
            abort_unless($handler instanceof ReportHandler, 500, 'Invalid report handler.');

            return $handler->run($user, $definition, $filters, $limit);
        }

        [$query, $columns, $summaryColumns] = $this->buildCoreReport($user, $definition, $filters);
        $total = (clone $query)->reorder()->count();

        if ($limit > self::PREVIEW_LIMIT && $total > self::EXPORT_LIMIT) {
            throw ValidationException::withMessages(['export' => 'This export contains more than '.number_format(self::EXPORT_LIMIT).' rows. Narrow the filters and try again.']);
        }

        $rows = (clone $query)->limit(min($limit, self::EXPORT_LIMIT))->get()
            ->map(fn (object $row): array => (array) $row);
        $rows = $this->presentRows($key, $rows);
        $summary = ['Rows' => $total];
        if ($summaryColumns !== []) {
            $summaryRow = DB::query()->fromSub((clone $query)->reorder(), 'report_summary')
                ->selectRaw(collect($summaryColumns)->map(fn (string $column, string $label): string => "COALESCE(SUM({$column}), 0) AS \"{$label}\"")->implode(', '))
                ->first();
            foreach ($summaryColumns as $label => $column) {
                $summary[$label] = is_numeric($summaryRow->{$label}) ? (float) $summaryRow->{$label} : 0;
            }
        }

        return new ReportResult($key, $definition->title, $columns, $rows, $summary, $filters, $total);
    }

    /**
     * @param  array<int, string>  $requestedFilters
     * @return array<string, array<int|string, string>>
     */
    public function filterOptions(User $user, array $requestedFilters = [], string $productSearch = '', ?int $selectedProductId = null): array
    {
        $needs = fn (string $filter): bool => $requestedFilters === [] || in_array($filter, $requestedFilters, true);
        $employees = ($needs('employee') || $needs('team'))
            ? $this->hrScope->employeeQuery($user, true)->orderBy('name')->pluck('name', 'id')->all()
            : [];
        $products = [];
        if ($needs('product') && (mb_strlen(trim($productSearch)) >= 2 || $selectedProductId !== null)) {
            $products = $this->productScope->apply(DB::table('products'), 'products.id', $user)
                ->when(mb_strlen(trim($productSearch)) >= 2, fn (Builder $query) => $query->where(function (Builder $query) use ($productSearch): void {
                    $query->where('products.sku', 'like', '%'.trim($productSearch).'%')
                        ->orWhere('products.name', 'like', '%'.trim($productSearch).'%');
                }))
                ->when($selectedProductId !== null && mb_strlen(trim($productSearch)) < 2, fn (Builder $query) => $query->where('products.id', $selectedProductId))
                ->orderBy('products.sku')
                ->limit(50)
                ->get(['products.id', 'products.sku', 'products.name'])
                ->mapWithKeys(fn (object $product): array => [$product->id => $product->sku.' · '.str($product->name)->limit(70)])
                ->all();
        }

        return [
            'statuses' => [],
            'employees' => $employees,
            'teams' => $needs('team')
                ? DB::table('teams')->where('status', true)->whereIn('id', DB::table('employees')->whereIn('id', array_keys($employees))->pluck('team_id')->filter())->orderBy('name')->pluck('name', 'id')->all()
                : [],
            'platforms' => $needs('platform') ? DB::table('marketplace_platforms')->where('status', true)->orderBy('name')->pluck('name', 'id')->all() : [],
            'products' => $products,
            'brands' => $needs('brand') ? DB::table('product_brands')->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all() : [],
            'warehouses' => $needs('warehouse') ? DB::table('warehouses')->where('status', true)->orderBy('name')->pluck('name', 'id')->all() : [],
            'suppliers' => $needs('supplier') ? DB::table('suppliers')->where('status', true)->orderBy('name')->pluck('name', 'id')->all() : [],
            'expenseCategories' => $needs('category') ? ExpenseCategory::options() : [],
            'expenseCostCenters' => $needs('cost_center') ? ExpenseCostCenter::options() : [],
            'webSalesChannels' => $needs('channel') ? collect(WebSalesChannel::cases())->mapWithKeys(
                fn (WebSalesChannel $channel): array => [$channel->value => $channel->getLabel()],
            )->all() : [],
            'officeFinanceAccounts' => $needs('office_account') ? DB::table('office_finance_accounts')->where('active', true)->orderBy('name')->pluck('name', 'id')->all() : [],
        ];
    }

    /** @return array{Builder, array<int, array{key:string,label:string,type?:string}>, array<string,string>} */
    private function buildCoreReport(User $user, ReportDefinition $definition, array $filters): array
    {
        $method = $definition->handlerMethod;
        abort_unless($method !== null && method_exists($this, $method), 500, 'Invalid report definition.');

        return $this->{$method}($user, $filters, ...$definition->handlerArguments);
    }

    private function orders(User $user, array $f): array
    {
        $ids = $this->orderIds($user, $f);
        $q = DB::table('order_items as ri')->join('orders as ro', 'ro.id', '=', 'ri.order_id')
            ->leftJoin('marketplace_platforms as rp', 'rp.id', '=', 'ro.marketplace_platform_id')
            ->leftJoin('employees as re', 're.id', '=', 'ro.handled_by_employee_id')
            ->whereIn('ro.id', $ids)->select([
                'ro.reference as order_reference', 'rp.name as platform', 'ro.external_order_number', 'ro.order_date', 'ro.status',
                'ri.sku', 'ri.product_name as product', 'ri.ordered_quantity as quantity', 're.name as handled_by',
            ])->selectRaw('(SELECT COALESCE(SUM(rfi.quantity), 0) FROM order_fulfillment_items rfi WHERE rfi.order_item_id = ri.id) AS fulfilled_quantity')
            ->orderByDesc('ro.order_date')->orderByDesc('ro.id');
        $columns = $this->columns(['order_reference' => 'Order Reference', 'platform' => 'Platform', 'external_order_number' => 'External Order ID', 'order_date' => 'Order Date', 'status' => 'Status', 'sku' => 'SKU', 'product' => 'Product', 'quantity' => 'Ordered Qty', 'fulfilled_quantity' => 'Fulfilled Qty', 'handled_by' => 'Handled By'], ['quantity', 'fulfilled_quantity']);
        $summary = ['Units' => 'quantity'];
        if ($this->orderAuth->allows($user, OrderPermission::ViewSellingPrice)) {
            $q->addSelect(['ri.selling_price', 'ri.line_total']);
            array_push($columns, ['key' => 'selling_price', 'label' => 'Unit Selling Price', 'type' => 'money'], ['key' => 'line_total', 'label' => 'Line Total', 'type' => 'money']);
            $summary['Sales Value'] = 'line_total';
        }

        return [$q, $columns, $summary];
    }

    private function salesSummary(User $user, array $f): array
    {
        [$base] = $this->productSales($user, $f);
        $q = DB::query()->fromSub($base, 'sales_lines')->selectRaw('platform, COUNT(*) AS product_lines, SUM(units_sold) AS units_sold')->groupBy('platform')->orderByDesc('units_sold');
        $columns = $this->columns(['platform' => 'Platform', 'product_lines' => 'Product Lines', 'units_sold' => 'Units Sold'], ['product_lines', 'units_sold']);
        $summary = ['Units' => 'units_sold'];
        if ($this->orderAuth->allows($user, OrderPermission::ViewSellingPrice)) {
            $q->addSelect(DB::raw('SUM(sales_value) AS sales_value'));
            $columns[] = ['key' => 'sales_value', 'label' => 'Sales Value', 'type' => 'money'];
            $summary['Sales Value'] = 'sales_value';
        }

        return [$q, $columns, $summary];
    }

    private function productSales(User $user, array $f): array
    {
        $ids = $this->orderIds($user, $f);
        $q = DB::table('order_fulfillment_items as sf')->join('order_fulfillments as sff', 'sff.id', '=', 'sf.order_fulfillment_id')
            ->join('orders as so', 'so.id', '=', 'sff.order_id')->join('products as sp', 'sp.id', '=', 'sf.product_id')
            ->leftJoin('marketplace_platforms as smp', 'smp.id', '=', 'so.marketplace_platform_id')->whereIn('so.id', $ids)
            ->when($f['product_id'], fn (Builder $x) => $x->where('sp.id', $f['product_id']))
            ->when($f['brand_id'], fn (Builder $x) => $x->where('sp.brand_id', $f['brand_id']))
            ->select(['smp.name as platform', 'sp.sku', 'sp.name as product'])->selectRaw('SUM(sf.quantity) AS units_sold')
            ->groupBy('smp.name', 'sp.id', 'sp.sku', 'sp.name')->orderByDesc('units_sold');
        $columns = $this->columns(['platform' => 'Platform', 'sku' => 'SKU', 'product' => 'Product', 'units_sold' => 'Units Sold'], ['units_sold']);
        $summary = ['Units' => 'units_sold'];
        if ($this->orderAuth->allows($user, OrderPermission::ViewSellingPrice)) {
            $q->selectRaw('SUM(sf.quantity * (SELECT oi.selling_price FROM order_items oi WHERE oi.id = sf.order_item_id)) AS sales_value');
            $columns[] = ['key' => 'sales_value', 'label' => 'Sales Value', 'type' => 'money'];
            $summary['Sales Value'] = 'sales_value';
        }

        return [$q, $columns, $summary];
    }

    private function inventory(User $user, array $f, string $type): array
    {
        $q = DB::table('product_inventories as ii')->join('products as ip', 'ip.id', '=', 'ii.product_id')->join('warehouses as iw', 'iw.id', '=', 'ii.warehouse_id')
            ->when($f['warehouse_id'], fn (Builder $x) => $x->where('iw.id', $f['warehouse_id']))
            ->when($f['product_id'], fn (Builder $x) => $x->where('ip.id', $f['product_id']))
            ->when($f['brand_id'], fn (Builder $x) => $x->where('ip.brand_id', $f['brand_id']))
            ->select(['ip.sku', 'ip.name as product', 'iw.name as location', 'ii.available_quantity as available', 'ii.reserved_quantity as reserved'])
            ->selectRaw('(ii.available_quantity - ii.reserved_quantity) AS sellable')
            ->addSelect(['ii.damaged_quantity as damaged', 'ii.qc_pending_quantity as qc_pending', 'ii.marketplace_non_sellable_quantity as marketplace_non_sellable']);
        $this->productScope->applyInventories($q, 'ii', $user);
        match ($type) {
            'low_stock' => $q->whereRaw('(ii.available_quantity - ii.reserved_quantity) BETWEEN 1 AND ?', [DashboardInventoryIntelligenceService::LOW_STOCK_THRESHOLD]),
            'out_of_stock' => $q->whereRaw('(ii.available_quantity - ii.reserved_quantity) <= 0'),
            'damaged_stock' => $q->where('ii.damaged_quantity', '>', 0),
            'qc_pending' => $q->where('ii.qc_pending_quantity', '>', 0),
            default => null,
        };
        $q->orderBy('ip.sku')->orderBy('iw.name');
        $columns = $this->columns(['sku' => 'SKU', 'product' => 'Product', 'location' => 'Location', 'available' => 'Available', 'reserved' => 'Reserved', 'sellable' => 'Sellable', 'damaged' => 'Damaged', 'qc_pending' => 'QC Pending', 'marketplace_non_sellable' => 'Marketplace Non-Sellable'], ['available', 'reserved', 'sellable', 'damaged', 'qc_pending', 'marketplace_non_sellable']);
        $summary = ['Sellable Units' => 'sellable'];
        if ($this->inventoryAuth->allows($user, InventoryPermission::ViewFinancials)) {
            $q->addSelect(['ii.average_cost'])->selectRaw('(ii.available_quantity + ii.damaged_quantity + ii.qc_pending_quantity + ii.marketplace_non_sellable_quantity) * ii.average_cost AS inventory_value');
            array_push($columns, ['key' => 'average_cost', 'label' => 'Average Cost', 'type' => 'money'], ['key' => 'inventory_value', 'label' => 'Inventory Value', 'type' => 'money']);
            $summary['Inventory Value'] = 'inventory_value';
        }

        return [$q, $columns, $summary];
    }

    private function movements(User $user, array $f): array
    {
        $q = DB::table('stock_movements as ms')->join('products as mp', 'mp.id', '=', 'ms.product_id')->join('warehouses as mw', 'mw.id', '=', 'ms.warehouse_id')->leftJoin('users as mu', 'mu.id', '=', 'ms.actor_user_id')
            ->whereIn('ms.product_inventory_id', $this->productScope->inventoryIds($user))
            ->when($f['warehouse_id'], fn (Builder $x) => $x->where('mw.id', $f['warehouse_id']))->when($f['product_id'], fn (Builder $x) => $x->where('mp.id', $f['product_id']))
            ->when($f['status'], fn (Builder $x) => $x->where('ms.movement_type', $f['status']))
            ->whereBetween('ms.occurred_at', [$f['from'].' 00:00:00', $f['to'].' 23:59:59'])
            ->select(['ms.reference', 'ms.occurred_at', 'mp.sku', 'mp.name as product', 'mw.name as location', 'ms.movement_type', 'ms.quantity', 'ms.available_delta', 'ms.reserved_delta', 'ms.damaged_delta', 'ms.reason', 'mu.name as actor'])->orderByDesc('ms.occurred_at');
        $columns = $this->columns(['reference' => 'Movement Reference', 'occurred_at' => 'Occurred At', 'sku' => 'SKU', 'product' => 'Product', 'location' => 'Location', 'movement_type' => 'Movement Type', 'quantity' => 'Quantity', 'available_delta' => 'Available Δ', 'reserved_delta' => 'Reserved Δ', 'damaged_delta' => 'Damaged Δ', 'reason' => 'Reason', 'actor' => 'Actor'], ['quantity', 'available_delta', 'reserved_delta', 'damaged_delta']);

        return [$q, $columns, ['Net Quantity' => 'quantity']];
    }

    private function purchases(User $user, array $f, bool $outstanding): array
    {
        $q = DB::table('purchase_items as pi')->join('purchases as po', 'po.id', '=', 'pi.purchase_id')->join('products as pp', 'pp.id', '=', 'pi.product_id')
            ->leftJoin('suppliers as ps', 'ps.id', '=', 'po.supplier_id')->join('warehouses as pw', 'pw.id', '=', 'po.warehouse_id')
            ->when($f['status'], fn (Builder $x) => $x->where('po.status', $f['status']))->when($f['supplier_id'], fn (Builder $x) => $x->where('ps.id', $f['supplier_id']))
            ->when($f['warehouse_id'], fn (Builder $x) => $x->where('pw.id', $f['warehouse_id']))->when($f['product_id'], fn (Builder $x) => $x->where('pp.id', $f['product_id']))
            ->when(! $outstanding, fn (Builder $x) => $x->whereBetween('po.purchase_date', [$f['from'], $f['to']]))
            ->when($outstanding, fn (Builder $x) => $x->whereRaw('(pi.ordered_quantity - pi.received_quantity - pi.rejected_quantity) > 0'))
            ->select(['po.reference as purchase_reference', 'po.purchase_date', 'ps.name as supplier', 'pw.name as location', 'po.status', 'pp.sku', 'pp.name as product', 'pi.ordered_quantity', 'pi.received_quantity', 'pi.rejected_quantity'])
            ->selectRaw('(pi.ordered_quantity - pi.received_quantity - pi.rejected_quantity) AS outstanding_quantity')->orderByDesc('po.purchase_date');
        $this->productScope->apply($q, 'pp.id', $user);
        $columns = $this->columns(['purchase_reference' => 'PO Reference', 'purchase_date' => 'Purchase Date', 'supplier' => 'Supplier', 'location' => 'Location', 'status' => 'Status', 'sku' => 'SKU', 'product' => 'Product', 'ordered_quantity' => 'Ordered Qty', 'received_quantity' => 'Received Qty', 'rejected_quantity' => 'Rejected Qty', 'outstanding_quantity' => 'Outstanding Qty'], ['ordered_quantity', 'received_quantity', 'rejected_quantity', 'outstanding_quantity']);
        if ($this->purchaseAuth->allows($user, PurchasePermission::ViewFinancials)) {
            $q->addSelect(['pi.unit_cost', 'pi.line_total']);
            array_push($columns, ['key' => 'unit_cost', 'label' => 'Unit Cost', 'type' => 'money'], ['key' => 'line_total', 'label' => 'Line Total', 'type' => 'money']);
        }

        return [$q, $columns, ['Ordered Units' => 'ordered_quantity', 'Outstanding Units' => 'outstanding_quantity']];
    }

    private function receiving(User $user, array $f): array
    {
        $q = DB::table('purchase_receipt_items as gri')->join('purchase_receipts as gr', 'gr.id', '=', 'gri.purchase_receipt_id')->join('purchases as gp', 'gp.id', '=', 'gr.purchase_id')->join('products as gpr', 'gpr.id', '=', 'gri.product_id')->join('warehouses as gw', 'gw.id', '=', 'gr.warehouse_id')->leftJoin('suppliers as gs', 'gs.id', '=', 'gp.supplier_id')
            ->whereBetween('gr.received_at', [$f['from'].' 00:00:00', $f['to'].' 23:59:59'])->when($f['supplier_id'], fn (Builder $x) => $x->where('gs.id', $f['supplier_id']))
            ->when($f['warehouse_id'], fn (Builder $x) => $x->where('gw.id', $f['warehouse_id']))->when($f['product_id'], fn (Builder $x) => $x->where('gpr.id', $f['product_id']))
            ->select(['gp.reference as purchase_reference', 'gr.reference as grn_reference', 'gr.received_at', 'gs.name as supplier', 'gw.name as location', 'gpr.sku', 'gpr.name as product', 'gri.quantity_received', 'gri.accepted_quantity', 'gri.damaged_quantity', 'gri.rejected_quantity'])->orderByDesc('gr.received_at');
        $this->productScope->apply($q, 'gpr.id', $user);
        $columns = $this->columns(['purchase_reference' => 'PO Reference', 'grn_reference' => 'GRN Reference', 'received_at' => 'Received At', 'supplier' => 'Supplier', 'location' => 'Location', 'sku' => 'SKU', 'product' => 'Product', 'quantity_received' => 'Received Qty', 'accepted_quantity' => 'Accepted Qty', 'damaged_quantity' => 'Damaged Qty', 'rejected_quantity' => 'Rejected Qty'], ['quantity_received', 'accepted_quantity', 'damaged_quantity', 'rejected_quantity']);
        if ($this->purchaseAuth->allows($user, PurchasePermission::ViewFinancials)) {
            $q->addSelect(['gri.inventory_unit_cost']);
            $columns[] = ['key' => 'inventory_unit_cost', 'label' => 'Inventory Unit Cost', 'type' => 'money'];
        }

        return [$q, $columns, ['Accepted Units' => 'accepted_quantity']];
    }

    private function returns(User $user, array $f): array
    {
        $ids = $this->returnReads->query($user)->select('customer_returns.id');
        $q = DB::table('customer_return_items as cri')->join('customer_returns as cr', 'cr.id', '=', 'cri.customer_return_id')->join('products as crp', 'crp.id', '=', 'cri.product_id')->leftJoin('marketplace_platforms as crm', 'crm.id', '=', 'cr.marketplace_platform_id')
            ->whereIn('cr.id', $ids)->whereBetween('cr.reported_at', [$f['from'].' 00:00:00', $f['to'].' 23:59:59'])
            ->when($f['status'], fn (Builder $x) => $x->where('cr.status', $f['status']))->when($f['platform_id'], fn (Builder $x) => $x->where('cr.marketplace_platform_id', $f['platform_id']))->when($f['product_id'], fn (Builder $x) => $x->where('crp.id', $f['product_id']))
            ->select(['cr.reference as return_reference', 'crm.name as platform', 'cr.reported_at', 'cr.received_at', 'cr.status', 'cr.return_source', 'cri.sku_snapshot as sku', 'cri.product_name_snapshot as product', 'cri.return_quantity', 'cri.return_reason'])->orderByDesc('cr.reported_at');
        $columns = $this->columns(['return_reference' => 'Return Reference', 'platform' => 'Platform', 'reported_at' => 'Reported At', 'received_at' => 'Received At', 'status' => 'Status', 'return_source' => 'Source', 'sku' => 'SKU', 'product' => 'Product', 'return_quantity' => 'Return Qty', 'return_reason' => 'Reason'], ['return_quantity']);

        return [$q, $columns, ['Returned Units' => 'return_quantity']];
    }

    private function claims(User $user, array $f): array
    {
        $ids = $this->claimAuth->scopeQuery(SafetClaim::query(), $user)->select('safet_claims.id');
        $q = DB::table('safet_claims as sc')->join('products as sp', 'sp.id', '=', 'sc.product_id')->leftJoin('marketplace_platforms as sm', 'sm.id', '=', 'sc.marketplace_platform_id')->leftJoin('customer_returns as sr', 'sr.id', '=', 'sc.customer_return_id')->leftJoin('orders as so', 'so.id', '=', 'sc.order_id')
            ->whereIn('sc.id', $ids)->whereBetween('sc.created_at', [$f['from'].' 00:00:00', $f['to'].' 23:59:59'])->when($f['status'], fn (Builder $x) => $x->where('sc.status', $f['status']))->when($f['platform_id'], fn (Builder $x) => $x->where('sc.marketplace_platform_id', $f['platform_id']))->when($f['product_id'], fn (Builder $x) => $x->where('sp.id', $f['product_id']))
            ->select(['sc.reference as claim_reference', 'sr.reference as return_reference', 'so.reference as order_reference', 'sm.name as platform', 'sp.sku', 'sp.name as product', 'sc.quantity', 'sc.status', 'sc.filed_at', 'sc.approved_at', 'sc.paid_at', 'sc.external_claim_reference'])->orderByDesc('sc.created_at');
        $columns = $this->columns(['claim_reference' => 'Claim Reference', 'return_reference' => 'Return Reference', 'order_reference' => 'Order Reference', 'platform' => 'Platform', 'sku' => 'SKU', 'product' => 'Product', 'quantity' => 'Qty', 'status' => 'Status', 'filed_at' => 'Filed At', 'approved_at' => 'Approved At', 'paid_at' => 'Paid At', 'external_claim_reference' => 'External Claim Ref'], ['quantity']);
        if ($this->claimAuth->allows($user, SafetClaimPermission::ViewFinancial)) {
            $q->addSelect(['sc.claimed_amount', 'sc.approved_amount', 'sc.reimbursed_amount', 'sc.currency']);
            array_push($columns, ['key' => 'claimed_amount', 'label' => 'Claimed Amount', 'type' => 'money'], ['key' => 'approved_amount', 'label' => 'Approved Amount', 'type' => 'money'], ['key' => 'reimbursed_amount', 'label' => 'Reimbursed Amount', 'type' => 'money'], ['key' => 'currency', 'label' => 'Currency']);
        }

        return [$q, $columns, ['Claimed Units' => 'quantity']];
    }

    private function refundExposure(User $user, array $f): array
    {
        $returnIds = $this->returnReads->query($user)->select('customer_returns.id');
        $q = DB::table('customer_return_refunds as rf')
            ->join('customer_returns as rr', 'rr.id', '=', 'rf.customer_return_id')
            ->join('orders as ro', 'ro.id', '=', 'rr.order_id')
            ->leftJoin('marketplace_platforms as rp', 'rp.id', '=', 'rr.marketplace_platform_id')
            ->leftJoin('warranty_repairs as rw', 'rw.id', '=', 'rf.warranty_repair_id')
            ->whereIn('rr.id', $returnIds)
            ->whereBetween('rf.created_at', [$f['from'].' 00:00:00', $f['to'].' 23:59:59'])
            ->when($f['status'], fn (Builder $query) => $query->where('rf.status', $f['status']))
            ->when($f['platform_id'], fn (Builder $query) => $query->where('rr.marketplace_platform_id', $f['platform_id']))
            ->when($f['product_id'], fn (Builder $query) => $query->whereExists(fn (Builder $items) => $items
                ->selectRaw('1')->from('customer_return_items as rfi')
                ->whereColumn('rfi.customer_return_id', 'rr.id')->where('rfi.product_id', $f['product_id'])))
            ->select([
                'rr.reference as return_reference', 'ro.reference as order_reference', 'rw.reference as warranty_reference',
                'rp.name as platform', 'rf.return_type', 'rf.status', 'rf.refund_amount', 'rf.currency',
                'rf.refund_date', 'rf.external_refund_reference', 'rf.created_at as recorded_at',
            ])->orderByDesc('rf.created_at');
        $columns = $this->columns([
            'return_reference' => 'Return Reference', 'order_reference' => 'Order Reference',
            'warranty_reference' => 'Warranty Reference', 'platform' => 'Platform', 'return_type' => 'Return Type',
            'status' => 'Refund Status', 'refund_date' => 'Refund Date',
            'external_refund_reference' => 'External Refund Ref', 'recorded_at' => 'Recorded At',
        ]);
        array_splice($columns, 6, 0, [
            ['key' => 'refund_amount', 'label' => 'Refund Amount', 'type' => 'money'],
            ['key' => 'currency', 'label' => 'Currency'],
        ]);

        return [$q, $columns, ['Refund Amount' => 'refund_amount']];
    }

    private function performance(User $user, array $f): array
    {
        $employeeIds = $this->performanceAuth->employeeQuery($user)->select('employees.id');
        $q = DB::table('employees as pe')->leftJoin('teams as pt', 'pt.id', '=', 'pe.team_id')
            ->whereIn('pe.id', $employeeIds)
            ->when($f['employee_id'], fn (Builder $query) => $query->where('pe.id', $f['employee_id']))
            ->when($f['team_id'], fn (Builder $query) => $query->where('pe.team_id', $f['team_id']))
            ->select(['pe.employee_id as employee_reference', 'pe.name as employee', 'pt.name as team'])
            ->selectRaw("(SELECT COUNT(*) FROM task_assignments pta WHERE pta.employee_id = pe.id AND pta.status = 'completed' AND date(pta.completed_at) BETWEEN ? AND ?) AS tasks_completed", [$f['from'], $f['to']])
            ->selectRaw("(SELECT COUNT(*) FROM task_assignments pta WHERE pta.employee_id = pe.id AND pta.status NOT IN ('completed','cancelled','removed') AND pta.task_id IN (SELECT id FROM tasks WHERE due_at < CURRENT_TIMESTAMP)) AS tasks_overdue")
            ->selectRaw("(SELECT COUNT(*) FROM employee_attendances pea WHERE pea.employee_id = pe.id AND pea.status = 'present' AND pea.attendance_date BETWEEN ? AND ?) AS present_days", [$f['from'], $f['to']])
            ->selectRaw("(SELECT COUNT(*) FROM employee_attendances pea WHERE pea.employee_id = pe.id AND pea.status = 'late' AND pea.attendance_date BETWEEN ? AND ?) AS late_occurrences", [$f['from'], $f['to']])
            ->selectRaw("(SELECT COUNT(*) FROM employee_attendances pea WHERE pea.employee_id = pe.id AND pea.status = 'absent' AND pea.attendance_date BETWEEN ? AND ?) AS actual_absences", [$f['from'], $f['to']])
            ->orderBy('pe.name');
        $columns = $this->columns([
            'employee_reference' => 'Employee ID', 'employee' => 'Employee', 'team' => 'Team',
            'tasks_completed' => 'Tasks Completed', 'tasks_overdue' => 'Tasks Overdue',
            'present_days' => 'Present Days', 'late_occurrences' => 'Late Occurrences',
            'actual_absences' => 'Actual Absences',
        ], ['tasks_completed', 'tasks_overdue', 'present_days', 'late_occurrences', 'actual_absences']);

        return [$q, $columns, ['Tasks Completed' => 'tasks_completed', 'Late Occurrences' => 'late_occurrences', 'Actual Absences' => 'actual_absences']];
    }

    private function warranty(User $user, array $f, bool $internal): array
    {
        $ids = $this->warrantyAuth->scopeQuery(WarrantyRepair::query(), $user)->select('warranty_repairs.id');
        $q = DB::table('warranty_repairs as wr')->join('products as wp', 'wp.id', '=', 'wr.product_id')->leftJoin('marketplace_platforms as wm', 'wm.id', '=', 'wr.marketplace_platform_id')->leftJoin('orders as wo', 'wo.id', '=', 'wr.order_id')->leftJoin('customer_returns as wret', 'wret.id', '=', 'wr.customer_return_id')->leftJoin('users as wa', 'wa.id', '=', 'wr.assigned_to_user_id')
            ->whereIn('wr.id', $ids)->whereBetween('wr.received_at', [$f['from'].' 00:00:00', $f['to'].' 23:59:59'])->when($internal, fn (Builder $x) => $x->where('wr.source', 'damaged_item'))->when(! $internal, fn (Builder $x) => $x->where('wr.source', '!=', 'damaged_item'))
            ->when($f['status'], fn (Builder $x) => $x->where('wr.status', $f['status']))->when($f['platform_id'], fn (Builder $x) => $x->where('wr.marketplace_platform_id', $f['platform_id']))->when($f['product_id'], fn (Builder $x) => $x->where('wp.id', $f['product_id']))
            ->select(['wr.reference as warranty_reference', 'wp.sku', 'wp.name as product', 'wr.quantity', 'wm.name as platform', 'wo.reference as order_reference', 'wret.reference as return_reference', 'wr.received_at', 'wr.sent_to_technician_at', 'wr.expected_return_at', 'wr.repair_completed_at', 'wr.dispatched_back_at', 'wr.completed_at', 'wr.status', 'wr.service_provider', 'wa.name as assigned_to'])->orderByDesc('wr.received_at');
        $columns = $this->columns(['warranty_reference' => 'Warranty Reference', 'sku' => 'SKU', 'product' => 'Product', 'quantity' => 'Qty', 'platform' => 'Platform', 'order_reference' => 'Order Reference', 'return_reference' => 'Return Reference', 'received_at' => 'Received At', 'sent_to_technician_at' => 'Sent to Technician', 'expected_return_at' => 'Expected Return', 'repair_completed_at' => 'Repair Completed', 'dispatched_back_at' => 'Dispatched Back', 'completed_at' => 'Completed At', 'status' => 'Status', 'service_provider' => 'Technician / Provider', 'assigned_to' => 'Assigned To'], ['quantity']);

        return [$q, $columns, ['Units' => 'quantity']];
    }

    private function complaints(User $user, array $f): array
    {
        $ids = $this->complaintAuth->scopeQuery(Complaint::query(), $user)->select('complaints.id');
        $q = DB::table('complaints as co')->leftJoin('products as cp', 'cp.id', '=', 'co.product_id')->leftJoin('marketplace_platforms as cm', 'cm.id', '=', 'co.marketplace_platform_id')->leftJoin('orders as cord', 'cord.id', '=', 'co.order_id')->leftJoin('users as ca', 'ca.id', '=', 'co.assigned_to_user_id')
            ->whereIn('co.id', $ids)->whereBetween('co.opened_at', [$f['from'].' 00:00:00', $f['to'].' 23:59:59'])->when($f['status'], fn (Builder $x) => $x->where('co.status', $f['status']))->when($f['platform_id'], fn (Builder $x) => $x->where('co.marketplace_platform_id', $f['platform_id']))->when($f['product_id'], fn (Builder $x) => $x->where('cp.id', $f['product_id']))
            ->select(['co.reference as complaint_reference', 'cord.reference as order_reference', 'cm.name as platform', 'cp.sku', 'cp.name as product', 'co.category', 'co.quantity', 'co.status', 'co.opened_at', 'co.resolved_at', 'ca.name as assigned_to'])->orderByDesc('co.opened_at');
        $columns = $this->columns(['complaint_reference' => 'Complaint Reference', 'order_reference' => 'Order Reference', 'platform' => 'Platform', 'sku' => 'SKU', 'product' => 'Product', 'category' => 'Category', 'quantity' => 'Qty', 'status' => 'Status', 'opened_at' => 'Opened At', 'resolved_at' => 'Resolved At', 'assigned_to' => 'Assigned To'], ['quantity']);

        return [$q, $columns, ['Units' => 'quantity']];
    }

    private function tasks(User $user, array $f): array
    {
        $ids = $this->taskQueries->visible($user)->select('tasks.id');
        $q = DB::table('tasks as rt')->leftJoin('task_assignments as rta', fn ($join) => $join->on('rta.task_id', '=', 'rt.id')->whereNotIn('rta.status', ['cancelled', 'removed']))->leftJoin('employees as rte', 'rte.id', '=', 'rta.employee_id')->leftJoin('teams as rtt', 'rtt.id', '=', 'rta.team_id_at_assignment')
            ->whereIn('rt.id', $ids)->whereBetween('rt.created_at', [$f['from'].' 00:00:00', $f['to'].' 23:59:59'])->when($f['status'], fn (Builder $x) => $x->whereRaw('COALESCE(rta.status, rt.status) = ?', [$f['status']]))->when($f['employee_id'], fn (Builder $x) => $x->where('rta.employee_id', $f['employee_id']))->when($f['team_id'], fn (Builder $x) => $x->where('rta.team_id_at_assignment', $f['team_id']))
            ->select(['rt.reference as task_reference', 'rt.title', 'rt.priority', DB::raw('COALESCE(rta.status, rt.status) AS status'), 'rte.name as assigned_employee', 'rtt.name as assigned_team', 'rt.due_at', 'rta.started_at', 'rta.completed_at', 'rt.linked_type', 'rt.created_at'])->orderByDesc('rt.created_at');
        $columns = $this->columns(['task_reference' => 'Task Reference', 'title' => 'Title', 'priority' => 'Priority', 'status' => 'Status', 'assigned_employee' => 'Assigned Employee', 'assigned_team' => 'Assigned Team', 'due_at' => 'Due At', 'started_at' => 'Started At', 'completed_at' => 'Completed At', 'linked_type' => 'Linked Module', 'created_at' => 'Created At']);

        return [$q, $columns, []];
    }

    private function attendance(User $user, array $f): array
    {
        $ids = $this->attendance->query($user, CarbonImmutable::parse($f['from']), CarbonImmutable::parse($f['to']), $f['employee_id'], $f['team_id'])->select('employee_attendances.id');
        $q = DB::table('employee_attendances as ea')->join('employees as ee', 'ee.id', '=', 'ea.employee_id')->leftJoin('work_schedules as ew', 'ew.id', '=', 'ea.work_schedule_id')->whereIn('ea.id', $ids)
            ->when($f['status'], fn (Builder $x) => $x->where('ea.status', $f['status']))
            ->select(['ee.employee_id as employee_reference', 'ee.name as employee', 'ea.attendance_date', 'ew.name as schedule', 'ew.timezone as attendance_timezone', 'ea.first_check_in_at', 'ea.last_check_out_at', 'ea.status', 'ea.late_minutes', 'ea.early_departure_minutes'])
            ->selectRaw("CASE WHEN ea.first_check_in_at IS NOT NULL AND ea.last_check_out_at IS NULL AND ea.status NOT IN ('approved_leave','unpaid_leave','public_holiday','weekend_off','compensatory_off') THEN 'Yes' ELSE 'No' END AS missing_checkout")
            ->orderByDesc('ea.attendance_date')->orderBy('ee.name');
        $columns = $this->columns(['employee_reference' => 'Employee ID', 'employee' => 'Employee', 'attendance_date' => 'Date', 'schedule' => 'Schedule', 'first_check_in_at' => 'Check In', 'last_check_out_at' => 'Check Out', 'status' => 'Status', 'late_minutes' => 'Late Minutes', 'early_departure_minutes' => 'Early Checkout Minutes', 'missing_checkout' => 'Missing Checkout'], ['late_minutes', 'early_departure_minutes']);

        return [$q, $columns, ['Late Minutes' => 'late_minutes', 'Early Checkout Minutes' => 'early_departure_minutes']];
    }

    private function leave(User $user, array $f): array
    {
        $ids = $this->hrScope->leaveQuery($user)->select('leave_requests.id');
        $q = DB::table('leave_requests as lr')->join('employees as le', 'le.id', '=', 'lr.employee_id')->join('leave_types as lt', 'lt.id', '=', 'lr.leave_type_id')->leftJoin('users as lu', 'lu.id', '=', 'lr.decided_by_user_id')
            ->whereIn('lr.id', $ids)->whereDate('lr.from_date', '<=', $f['to'])->whereDate('lr.to_date', '>=', $f['from'])->when($f['status'], fn (Builder $x) => $x->where('lr.status', $f['status']))->when($f['employee_id'], fn (Builder $x) => $x->where('lr.employee_id', $f['employee_id']))->when($f['team_id'], fn (Builder $x) => $x->where('lr.team_id_at_request', $f['team_id']))
            ->select(['lr.reference as leave_reference', 'le.employee_id as employee_reference', 'le.name as employee', 'lt.name as leave_type', 'lr.from_date', 'lr.to_date', 'lr.requested_working_days as charged_days', 'lr.status', 'lr.submitted_at', 'lu.name as decided_by', 'lr.decided_at'])->orderByDesc('lr.from_date');
        $columns = $this->columns(['leave_reference' => 'Leave Reference', 'employee_reference' => 'Employee ID', 'employee' => 'Employee', 'leave_type' => 'Leave Type', 'from_date' => 'From', 'to_date' => 'To', 'charged_days' => 'Charged Days', 'status' => 'Status', 'submitted_at' => 'Requested At', 'decided_by' => 'Approver', 'decided_at' => 'Decided At'], ['charged_days']);

        return [$q, $columns, ['Charged Days' => 'charged_days']];
    }

    private function compOff(User $user, array $f): array
    {
        $employeeIds = $this->hrScope->employeeQuery($user, false)->select('employees.id');
        $q = DB::table('compensatory_offs as cc')->join('employees as ce', 'ce.id', '=', 'cc.employee_id')->whereIn('cc.employee_id', $employeeIds)->whereBetween('cc.earned_work_date', [$f['from'], $f['to']])->when($f['status'], fn (Builder $x) => $x->where('cc.status', $f['status']))->when($f['employee_id'], fn (Builder $x) => $x->where('cc.employee_id', $f['employee_id']))
            ->select(['cc.reference as comp_off_reference', 'ce.employee_id as employee_reference', 'ce.name as employee', 'cc.earned_work_date', 'cc.off_date', 'cc.source', 'cc.status', 'cc.approved_at', 'cc.used_at'])->orderByDesc('cc.earned_work_date');
        $columns = $this->columns(['comp_off_reference' => 'Comp Off Reference', 'employee_reference' => 'Employee ID', 'employee' => 'Employee', 'earned_work_date' => 'Earned Date', 'off_date' => 'Off Date', 'source' => 'Source', 'status' => 'Status', 'approved_at' => 'Approved At', 'used_at' => 'Used At']);

        return [$q, $columns, []];
    }

    private function warnings(User $user, array $f): array
    {
        $employees = $this->warningEmployeeIds($user);
        $q = DB::table('employee_warnings as hw')->join('employees as he', 'he.id', '=', 'hw.employee_id')->leftJoin('warning_categories as hc', 'hc.id', '=', 'hw.warning_category_id')->leftJoin('hr_acknowledgments as ha', fn ($join) => $join->on('ha.employee_warning_id', '=', 'hw.id')->on('ha.employee_id', '=', 'hw.employee_id'))
            ->whereIn('hw.employee_id', $employees)->whereBetween('hw.issued_date', [$f['from'], $f['to']])->when($f['status'], fn (Builder $x) => $x->where('hw.status', $f['status']))->when($f['employee_id'], fn (Builder $x) => $x->where('hw.employee_id', $f['employee_id']))->when($f['team_id'], fn (Builder $x) => $x->where('he.team_id', $f['team_id']))
            ->select(['hw.reference as warning_reference', 'he.employee_id as employee_reference', 'he.name as employee', 'hc.name as category', 'hw.warning_level', 'hw.title', 'hw.issued_date', 'hw.status', 'ha.acknowledged_at'])->orderByDesc('hw.issued_date');
        $columns = $this->columns(['warning_reference' => 'Warning Reference', 'employee_reference' => 'Employee ID', 'employee' => 'Employee', 'category' => 'Category', 'warning_level' => 'Level', 'title' => 'Title', 'issued_date' => 'Issue Date', 'status' => 'Status', 'acknowledged_at' => 'Acknowledged At']);

        return [$q, $columns, []];
    }

    private function notices(User $user, array $f): array
    {
        $management = $this->hrAuth->allows($user, HrPermission::NoticeManage) || $this->hrAuth->allows($user, HrPermission::NoticePublish);
        $q = DB::table('hr_notices as hn')->leftJoin('notice_categories as nc', 'nc.id', '=', 'hn.notice_category_id')->whereBetween('hn.published_at', [$f['from'].' 00:00:00', $f['to'].' 23:59:59'])->when($f['status'], fn (Builder $x) => $x->where('hn.status', $f['status']));
        if ($management) {
            $q->select(['hn.reference as notice_reference', 'nc.name as category', 'hn.title', 'hn.audience_type', 'hn.priority', 'hn.published_at', 'hn.expires_at', 'hn.status'])
                ->selectRaw('(SELECT COUNT(*) FROM hr_notice_recipients nr WHERE nr.hr_notice_id = hn.id) AS recipients')
                ->selectRaw('(SELECT COUNT(*) FROM hr_acknowledgments na WHERE na.hr_notice_id = hn.id AND na.read_at IS NOT NULL) AS read_count')
                ->selectRaw('(SELECT COUNT(*) FROM hr_acknowledgments na WHERE na.hr_notice_id = hn.id AND na.acknowledged_at IS NOT NULL) AS acknowledged_count');
            $columns = $this->columns(['notice_reference' => 'Notice Reference', 'category' => 'Category', 'title' => 'Title', 'audience_type' => 'Audience', 'priority' => 'Priority', 'recipients' => 'Recipients', 'read_count' => 'Read', 'acknowledged_count' => 'Acknowledged', 'published_at' => 'Published At', 'expires_at' => 'Expires At', 'status' => 'Status'], ['recipients', 'read_count', 'acknowledged_count']);
        } else {
            $q->join('hr_notice_recipients as nr', fn ($join) => $join->on('nr.hr_notice_id', '=', 'hn.id')->where('nr.employee_id', $user->employee->id))->leftJoin('hr_acknowledgments as na', fn ($join) => $join->on('na.hr_notice_id', '=', 'hn.id')->where('na.employee_id', $user->employee->id))
                ->select(['hn.reference as notice_reference', 'nc.name as category', 'hn.title', 'hn.priority', 'hn.published_at', 'hn.expires_at', 'hn.status', 'na.read_at', 'na.acknowledged_at']);
            $columns = $this->columns(['notice_reference' => 'Notice Reference', 'category' => 'Category', 'title' => 'Title', 'priority' => 'Priority', 'published_at' => 'Published At', 'expires_at' => 'Expires At', 'status' => 'Status', 'read_at' => 'Read At', 'acknowledged_at' => 'Acknowledged At']);
        }

        return [$q->orderByDesc('hn.published_at'), $columns, []];
    }

    private function orderIds(User $user, array $f)
    {
        $query = Order::query()->select('orders.id')->whereBetween('orders.order_date', [$f['from'], $f['to']])->when($f['status'], fn ($x) => $x->where('orders.status', $f['status']))->when($f['platform_id'], fn ($x) => $x->where('orders.marketplace_platform_id', $f['platform_id']))->when($f['product_id'], fn ($x) => $x->whereHas('items', fn ($i) => $i->where('product_id', $f['product_id'])));

        return $this->orderScope->applyOrders($query, $user);
    }

    private function warningEmployeeIds(User $user)
    {
        if ($this->hrAuth->allows($user, HrPermission::WarningViewAll)) {
            return DB::table('employees')->select('id');
        }
        if ($this->hrAuth->allows($user, HrPermission::WarningViewTeam) && $user->employee->team_id !== null) {
            return DB::table('employees')->where('team_id', $user->employee->team_id)->select('id');
        }

        return DB::table('employees')->where('id', $user->employee->id)->select('id');
    }

    private function presentRows(string $report, Collection $rows): Collection
    {
        return $rows->map(function (array $row) use ($report): array {
            if ($report === 'attendance') {
                $timezone = filled($row['attendance_timezone'] ?? null)
                    ? (string) $row['attendance_timezone']
                    : 'Asia/Karachi';
                $row['attendance_date'] = CarbonImmutable::parse($row['attendance_date'])->format('d M Y');
                foreach (['first_check_in_at', 'last_check_out_at'] as $field) {
                    $row[$field] = filled($row[$field] ?? null)
                        ? CarbonImmutable::parse((string) $row[$field], 'UTC')->timezone($timezone)->format('h:i A')
                        : '—';
                }
                unset($row['attendance_timezone']);
            }

            foreach (['status', 'priority', 'source', 'return_source', 'return_type', 'movement_type', 'linked_type', 'audience_type', 'document_type'] as $field) {
                if (is_string($row[$field] ?? null) && $row[$field] !== '') {
                    $row[$field] = Str::headline($row[$field]);
                }
            }

            return $row;
        });
    }

    private function normalizeFilters(array $filters): array
    {
        $today = CarbonImmutable::today(config('app.timezone'));
        $from = CarbonImmutable::parse($filters['from'] ?? $today->startOfMonth()->toDateString());
        $to = CarbonImmutable::parse($filters['to'] ?? $today->toDateString());
        if ($to->lt($from) || $from->diffInDays($to) > 366) {
            throw ValidationException::withMessages(['to' => 'The report date range must be valid and cannot exceed 366 days.']);
        }

        return [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'status' => filled($filters['status'] ?? null) ? (string) $filters['status'] : null,
            'employee_id' => filled($filters['employee_id'] ?? null) ? (int) $filters['employee_id'] : null,
            'team_id' => filled($filters['team_id'] ?? null) ? (int) $filters['team_id'] : null,
            'platform_id' => filled($filters['platform_id'] ?? null) ? (int) $filters['platform_id'] : null,
            'product_id' => filled($filters['product_id'] ?? null) ? (int) $filters['product_id'] : null,
            'brand_id' => filled($filters['brand_id'] ?? null) ? (int) $filters['brand_id'] : null,
            'warehouse_id' => filled($filters['warehouse_id'] ?? null) ? (int) $filters['warehouse_id'] : null,
            'supplier_id' => filled($filters['supplier_id'] ?? null) ? (int) $filters['supplier_id'] : null,
            'category' => filled($filters['category'] ?? null) ? (string) $filters['category'] : null,
            'cost_center' => filled($filters['cost_center'] ?? null) ? (string) $filters['cost_center'] : null,
            'channel' => filled($filters['channel'] ?? null) ? (string) $filters['channel'] : null,
            'office_account_id' => filled($filters['office_account_id'] ?? null) ? (int) $filters['office_account_id'] : null,
        ];
    }

    /** @param array<string, string> $labels @param array<int, string> $numeric */
    private function columns(array $labels, array $numeric = []): array
    {
        return collect($labels)->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label] + (in_array($key, $numeric, true) ? ['type' => 'number'] : []))->values()->all();
    }
}
