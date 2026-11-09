<?php

namespace App\Services\Reports\Handlers;

use App\Contracts\Reports\ReportHandler;
use App\DTOs\Reports\ReportDefinition;
use App\DTOs\Reports\ReportResult;
use App\Enums\WebSalesPermission;
use App\Models\User;
use App\Services\Authorization\WebSalesAuthorization;
use App\Services\Orders\WebSalesDashboardService;
use App\Services\Orders\WebSalesReadService;
use App\Services\Reports\ReportQueryService;
use App\Support\ConfiguredCogs;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WebSalesReportHandler implements ReportHandler
{
    public function __construct(
        private readonly WebSalesReadService $read,
        private readonly WebSalesDashboardService $dashboard,
        private readonly WebSalesAuthorization $authorization,
    ) {}

    public function run(User $user, ReportDefinition $definition, array $filters, int $limit): ReportResult
    {
        return match ($definition->key) {
            'web_sales.orders' => $this->orders($user, $definition, $filters, $limit),
            'web_sales.performance' => $this->performance($user, $definition, $filters, $limit),
            'web_sales.by_employee' => $this->byEmployee($user, $definition, $filters, $limit),
            'web_sales.by_channel' => $this->byChannel($user, $definition, $filters, $limit),
            'web_sales.profitability' => $this->profitability($user, $definition, $filters, $limit),
        };
    }

    private function orders(User $user, ReportDefinition $definition, array $filters, int $limit): ReportResult
    {
        $ids = $this->read->scoped($user)->select('orders.id');
        $query = DB::table('orders as wo')
            ->leftJoin('employees as we', 'we.id', '=', 'wo.handled_by_employee_id')
            ->leftJoin('warehouses as ww', 'ww.id', '=', 'wo.warehouse_id')
            ->whereIn('wo.id', $ids)
            ->whereBetween('wo.order_date', [$filters['from'], $filters['to']])
            ->when($filters['status'], fn (Builder $query) => $query->where('wo.status', $filters['status']))
            ->when($filters['employee_id'], fn (Builder $query) => $query->where('wo.handled_by_employee_id', $filters['employee_id']))
            ->when($filters['channel'], fn (Builder $query) => $query->where('wo.web_sales_channel', $filters['channel']))
            ->select([
                'wo.reference', 'wo.order_date', 'wo.web_sales_channel as channel', 'wo.status',
                'we.name as handled_by', 'ww.name as warehouse', 'wo.delivery_type', 'wo.delivered_at',
            ])
            ->selectRaw('(SELECT COALESCE(SUM(wi.ordered_quantity), 0) FROM order_items wi WHERE wi.order_id = wo.id) AS units')
            ->orderByDesc('wo.order_date')->orderByDesc('wo.id');
        $columns = $this->columns([
            'reference' => 'Order Reference', 'order_date' => 'Order Date', 'channel' => 'Channel',
            'status' => 'Status', 'handled_by' => 'Handled By', 'warehouse' => 'Warehouse',
            'delivery_type' => 'Delivery Type', 'units' => 'Units', 'delivered_at' => 'Delivered At',
        ], ['units']);

        if ($this->authorization->allows($user, WebSalesPermission::ViewRevenue)) {
            $query->addSelect('wo.grand_total as revenue');
            $columns[] = ['key' => 'revenue', 'label' => 'Revenue', 'type' => 'money'];
        }
        if ($this->authorization->allows($user, WebSalesPermission::ViewCost)) {
            $query->selectRaw($this->cogsSubquery().' AS cogs');
            $columns[] = ['key' => 'cogs', 'label' => 'COGS', 'type' => 'money'];
        }
        if ($this->authorization->allows($user, WebSalesPermission::ViewGrossProfit)) {
            $query->selectRaw('(wo.grand_total - '.$this->cogsSubquery().') AS gross_profit');
            $columns[] = ['key' => 'gross_profit', 'label' => 'Gross Profit', 'type' => 'money'];
        }
        if ($this->authorization->allows($user, WebSalesPermission::ViewCost)
            || $this->authorization->allows($user, WebSalesPermission::ViewGrossProfit)) {
            $query->selectRaw($this->costBasisSubquery().' AS cost_basis');
            $columns[] = ['key' => 'cost_basis', 'label' => 'Cost Basis'];
        }

        return $this->queryResult($definition, $filters, $query, $columns, $limit);
    }

    private function performance(User $user, ReportDefinition $definition, array $filters, int $limit): ReportResult
    {
        $metrics = $this->metrics($user, $filters);
        $rows = collect($metrics['statuses'])->map(fn (int $count, string $status): array => [
            'status' => str($status)->replace('_', ' ')->title()->toString(),
            'orders' => $count,
        ])->values();
        $summary = $this->authorizedSummary($metrics['summary']);

        return $this->collectionResult($definition, $filters, $rows, $this->columns(['status' => 'Lifecycle Stage', 'orders' => 'Orders'], ['orders']), $summary, $limit);
    }

    private function byEmployee(User $user, ReportDefinition $definition, array $filters, int $limit): ReportResult
    {
        $ids = $this->read->scoped($user)->select('orders.id');
        $query = DB::table('order_fulfillments as wf')
            ->join('orders as wo', 'wo.id', '=', 'wf.order_id')
            ->join('order_fulfillment_items as wfi', 'wfi.order_fulfillment_id', '=', 'wf.id')
            ->join('order_items as woi', 'woi.id', '=', 'wfi.order_item_id')
            ->leftJoin('employees as we', 'we.id', '=', 'wo.handled_by_employee_id')
            ->whereIn('wo.id', $ids)
            ->where('wf.fulfilled_at', '>=', $filters['from_utc'])->where('wf.fulfilled_at', '<', $filters['until_utc'])
            ->when($filters['employee_id'], fn (Builder $query) => $query->where('wo.handled_by_employee_id', $filters['employee_id']))
            ->groupBy('we.id', 'we.name')
            ->selectRaw("COALESCE(we.name, 'Unassigned') AS employee, COUNT(DISTINCT wo.id) AS orders, SUM(wfi.quantity) AS units")
            ->orderByDesc('units');
        $columns = $this->columns(['employee' => 'Employee', 'orders' => 'Orders', 'units' => 'Units'], ['orders', 'units']);
        if ($this->authorization->allows($user, WebSalesPermission::ViewRevenue)) {
            $query->selectRaw('SUM(woi.line_total) AS revenue');
            $columns[] = ['key' => 'revenue', 'label' => 'Revenue', 'type' => 'money'];
        }
        if ($this->authorization->allows($user, WebSalesPermission::ViewCost)) {
            $query->selectRaw('SUM('.ConfiguredCogs::sql('wfi').') AS cogs');
            $columns[] = ['key' => 'cogs', 'label' => 'COGS', 'type' => 'money'];
        }
        if ($this->authorization->allows($user, WebSalesPermission::ViewGrossProfit)) {
            $query->selectRaw('SUM(woi.line_total) - SUM('.ConfiguredCogs::sql('wfi').') AS gross_profit');
            $columns[] = ['key' => 'gross_profit', 'label' => 'Gross Profit', 'type' => 'money'];
        }

        return $this->queryResult($definition, $filters, $query, $columns, $limit);
    }

    private function byChannel(User $user, ReportDefinition $definition, array $filters, int $limit): ReportResult
    {
        $metrics = $this->metrics($user, $filters);
        $rows = collect($metrics['channels'])
            ->when($filters['channel'], fn (Collection $rows) => $rows->filter(fn (array $row): bool => str($row['channel'])->slug('_')->toString() === $filters['channel']))
            ->values();
        $columns = $this->columns(['channel' => 'Channel', 'orders' => 'Orders', 'units' => 'Units'], ['orders', 'units']);
        if ($metrics['can_revenue']) {
            $columns[] = ['key' => 'revenue', 'label' => 'Revenue', 'type' => 'money'];
        }
        if ($metrics['can_profit']) {
            $columns[] = ['key' => 'gross_profit', 'label' => 'Gross Profit', 'type' => 'money'];
        }

        return $this->collectionResult($definition, $filters, $rows, $columns, $this->authorizedSummary($metrics['summary']), $limit);
    }

    private function profitability(User $user, ReportDefinition $definition, array $filters, int $limit): ReportResult
    {
        $metrics = $this->metrics($user, $filters);
        $rows = collect($metrics['expense_breakdown'])->map(fn (array $row): array => [
            'category' => $row['category'],
            'operating_expense' => (float) $row['amount'],
        ]);

        return $this->collectionResult(
            $definition,
            $filters,
            $rows,
            [['key' => 'category', 'label' => 'Expense Breakdown'], ['key' => 'operating_expense', 'label' => 'Operating Expense', 'type' => 'money']],
            $this->authorizedSummary($metrics['summary']),
            $limit,
        );
    }

    /** @return array<string, mixed> */
    private function metrics(User $user, array $filters): array
    {
        return $this->dashboard->metrics(
            $user,
            CarbonImmutable::parse($filters['from']),
            CarbonImmutable::parse($filters['to']),
        );
    }

    /** @param array<string, mixed> $summary */
    private function authorizedSummary(array $summary): array
    {
        $labels = [
            'total_orders' => 'Orders', 'units_sold' => 'Units Sold', 'revenue' => 'Revenue',
            'cogs' => 'COGS', 'gross_profit' => 'Gross Profit', 'operating_expenses' => 'Operating Expenses',
            'net_profit' => 'Net Profit',
        ];

        return collect($labels)->filter(fn (string $label, string $key): bool => $summary[$key] !== null)
            ->mapWithKeys(fn (string $label, string $key): array => [$label => is_numeric($summary[$key]) ? (float) $summary[$key] : $summary[$key]])
            ->all();
    }

    private function cogsSubquery(): string
    {
        return '(SELECT CASE WHEN '.$this->pendingCostSubquery().' THEN NULL ELSE SUM(CASE
            WHEN EXISTS (SELECT 1 FROM order_fulfillment_items wfi_match WHERE wfi_match.order_item_id = woi.id)
                THEN (SELECT SUM('.ConfiguredCogs::sql('wfi').') FROM order_fulfillment_items wfi WHERE wfi.order_item_id = woi.id)
            WHEN EXISTS (SELECT 1 FROM web_sales_manual_sourcing_lines wsm WHERE wsm.order_item_id = woi.id)
                THEN (SELECT wsm.actual_unit_cost * wsm.quantity FROM web_sales_manual_sourcing_lines wsm WHERE wsm.order_item_id = woi.id LIMIT 1)
            ELSE (SELECT pi.average_cost * woi.ordered_quantity FROM product_inventories pi WHERE pi.product_id = woi.product_id AND pi.warehouse_id = wo.warehouse_id LIMIT 1)
        END) END FROM order_items woi WHERE woi.order_id = wo.id)';
    }

    private function pendingCostSubquery(): string
    {
        return 'EXISTS (SELECT 1 FROM order_items pending_oi WHERE pending_oi.order_id = wo.id AND NOT EXISTS (SELECT 1 FROM order_fulfillment_items pending_wfi WHERE pending_wfi.order_item_id = pending_oi.id) AND (EXISTS (SELECT 1 FROM web_sales_manual_sourcing_lines pending_wsm WHERE pending_wsm.order_item_id = pending_oi.id AND pending_wsm.actual_unit_cost IS NULL) OR (NOT EXISTS (SELECT 1 FROM web_sales_manual_sourcing_lines pending_wsm_any WHERE pending_wsm_any.order_item_id = pending_oi.id) AND NOT EXISTS (SELECT 1 FROM product_inventories pending_pi WHERE pending_pi.product_id = pending_oi.product_id AND pending_pi.warehouse_id = wo.warehouse_id AND pending_pi.average_cost IS NOT NULL)))';
    }

    private function costBasisSubquery(): string
    {
        return "(SELECT CASE WHEN {$this->pendingCostSubquery()} THEN 'Pending' WHEN EXISTS (SELECT 1 FROM order_items estimated_oi WHERE estimated_oi.order_id = wo.id AND NOT EXISTS (SELECT 1 FROM order_fulfillment_items estimated_wfi WHERE estimated_wfi.order_item_id = estimated_oi.id)) THEN 'Estimated' ELSE 'Actual' END)";
    }

    /** @param array<int, array{key:string,label:string,type?:string}> $columns */
    private function queryResult(ReportDefinition $definition, array $filters, Builder $query, array $columns, int $limit): ReportResult
    {
        $total = (clone $query)->reorder()->count();
        $this->enforceLimit($total, $limit);
        $rows = (clone $query)->limit(min($limit, ReportQueryService::EXPORT_LIMIT))->get()->map(function (object $row): array {
            $data = (array) $row;
            foreach (['channel', 'status', 'delivery_type'] as $key) {
                if (filled($data[$key] ?? null)) {
                    $data[$key] = str((string) $data[$key])->replace('_', ' ')->headline()->toString();
                }
            }

            return $data;
        });

        return new ReportResult($definition->key, $definition->title, $columns, $rows, ['Rows' => $total], $filters, $total);
    }

    /** @param array<int, array{key:string,label:string,type?:string}> $columns @param array<string, int|float|string> $summary */
    private function collectionResult(ReportDefinition $definition, array $filters, Collection $rows, array $columns, array $summary, int $limit): ReportResult
    {
        $total = $rows->count();
        $this->enforceLimit($total, $limit);

        return new ReportResult($definition->key, $definition->title, $columns, $rows->take(min($limit, ReportQueryService::EXPORT_LIMIT))->values(), $summary + ['Rows' => $total], $filters, $total);
    }

    private function enforceLimit(int $total, int $limit): void
    {
        if ($limit > ReportQueryService::PREVIEW_LIMIT && $total > ReportQueryService::EXPORT_LIMIT) {
            throw ValidationException::withMessages(['export' => 'This export contains more than '.number_format(ReportQueryService::EXPORT_LIMIT).' rows. Narrow the filters and try again.']);
        }
    }

    /** @param array<string, string> $labels @param array<int, string> $numeric */
    private function columns(array $labels, array $numeric = []): array
    {
        return collect($labels)->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label] + (in_array($key, $numeric, true) ? ['type' => 'number'] : []))->values()->all();
    }
}

