<?php

namespace App\Services\Orders;

use App\Enums\ExpenseCategory;
use App\Enums\ExpenseCostCenter;
use App\Enums\ExpensePermission;
use App\Enums\WebSalesChannel;
use App\Enums\WebSalesPermission;
use App\Models\User;
use App\Services\Authorization\ExpenseAuthorization;
use App\Services\Authorization\WebSalesAuthorization;
use App\Support\ConfiguredCogs;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WebSalesDashboardService
{
    public function __construct(
        private readonly WebSalesReadService $read,
        private readonly WebSalesAuthorization $authorization,
        private readonly ExpenseAuthorization $expenses,
    ) {}

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function range(string $period, string $from = '', string $to = ''): array
    {
        $today = CarbonImmutable::today(config('app.timezone'));

        return match ($period) {
            'yesterday' => [$today->subDay(), $today->subDay()],
            'week' => [$today->startOfWeek(), $today->endOfWeek()],
            'month' => [$today->startOfMonth(), $today->endOfMonth()],
            'custom' => [CarbonImmutable::parse($from)->startOfDay(), CarbonImmutable::parse($to)->startOfDay()],
            default => [$today, $today],
        };
    }

    /** @return array<string, mixed> */
    public function metrics(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $canRevenue = $this->authorization->allows($user, WebSalesPermission::ViewRevenue);
        $canCost = $this->authorization->allows($user, WebSalesPermission::ViewCost);
        $canProfit = $this->authorization->allows($user, WebSalesPermission::ViewGrossProfit);
        $canNetProfit = $canProfit
            && $this->expenses->allows($user, ExpensePermission::ViewAmount)
            && $this->expenses->allows($user, ExpensePermission::ViewNetProfit);
        $ids = $this->read->scoped($user)->select('orders.id');
        $orders = DB::table('orders')->whereIn('orders.id', clone $ids)
            ->whereDate('orders.order_date', '>=', $from->toDateString())
            ->whereDate('orders.order_date', '<=', $to->toDateString());
        $statusRows = (clone $orders)->selectRaw(<<<'SQL'
            SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS new_count,
            SUM(CASE WHEN status IN ('pending_review','confirmed','reserved','processing') THEN 1 ELSE 0 END) AS confirmed_count,
            SUM(CASE WHEN status = 'fulfilled' AND delivered_at IS NULL THEN 1 ELSE 0 END) AS shipped_count,
            SUM(CASE WHEN status = 'fulfilled' AND delivered_at IS NOT NULL THEN 1 ELSE 0 END) AS delivered_count,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count
            SQL)->first();
        $completed = $this->completedQuery($ids, $from, $to);
        $completedSelect = ['SUM(wfi.quantity) AS units'];
        if ($canRevenue || $canProfit) {
            $completedSelect[] = 'SUM(woi.line_total) AS revenue';
        }
        if ($canCost || $canProfit) {
            $completedSelect[] = 'SUM('.ConfiguredCogs::sql('wfi').') AS cogs';
        }
        $completedTotals = $completed->selectRaw(implode(', ', $completedSelect))->first();
        $revenueForProfit = ($canRevenue || $canProfit) ? (string) ($completedTotals->revenue ?? '0.00') : null;
        $cogs = ($canCost || $canProfit) ? (string) ($completedTotals->cogs ?? '0.0000') : null;
        $grossProfit = $canProfit && $revenueForProfit !== null && $cogs !== null ? bcsub($revenueForProfit, $cogs, 2) : null;
        $expenseBreakdown = collect();
        $operatingExpenses = null;
        if ($canNetProfit) {
            $expenseBreakdown = DB::table('expenses')
                ->where('cost_center', ExpenseCostCenter::WebSales->value)
                ->whereNull('voided_at')
                ->whereDate('expense_date', '>=', $from->toDateString())
                ->whereDate('expense_date', '<=', $to->toDateString())
                ->groupBy('category')
                ->selectRaw('category, SUM(amount) AS amount')
                ->orderByDesc('amount')
                ->get()
                ->map(fn ($row): array => [
                    'category' => ExpenseCategory::from($row->category)->label(),
                    'amount' => (string) $row->amount,
                ]);
            $operatingExpenses = $expenseBreakdown->reduce(
                fn (string $total, array $row): string => bcadd($total, $row['amount'], 2),
                '0.00',
            );
        }

        return [
            'summary' => [
                'total_orders' => (clone $orders)->count(),
                'units_sold' => (int) ($completedTotals->units ?? 0),
                'revenue' => $canRevenue ? $revenueForProfit : null,
                'cogs' => $canCost ? $cogs : null,
                'gross_profit' => $grossProfit,
                'operating_expenses' => $operatingExpenses,
                'net_profit' => $canNetProfit && $grossProfit !== null ? bcsub($grossProfit, $operatingExpenses, 2) : null,
            ],
            'statuses' => [
                'new' => (int) ($statusRows->new_count ?? 0),
                'confirmed' => (int) ($statusRows->confirmed_count ?? 0),
                'shipped' => (int) ($statusRows->shipped_count ?? 0),
                'delivered' => (int) ($statusRows->delivered_count ?? 0),
                'cancelled' => (int) ($statusRows->cancelled_count ?? 0),
            ],
            'employees' => $this->employeePerformance($user, $ids, $from, $to, $canRevenue, $canCost, $canProfit),
            'channels' => $this->channelPerformance($ids, $from, $to, $canRevenue, $canProfit),
            'products' => $this->productPerformance($ids, $from, $to, $canRevenue, $canProfit),
            'can_revenue' => $canRevenue,
            'can_cost' => $canCost,
            'can_profit' => $canProfit,
            'can_net_profit' => $canNetProfit,
            'expense_breakdown' => $expenseBreakdown,
        ];
    }

    private function completedQuery($ids, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return DB::table('order_fulfillments as wf')
            ->join('orders as wo', 'wo.id', '=', 'wf.order_id')
            ->join('order_fulfillment_items as wfi', 'wfi.order_fulfillment_id', '=', 'wf.id')
            ->join('order_items as woi', 'woi.id', '=', 'wfi.order_item_id')
            ->whereIn('wo.id', clone $ids)
            ->whereBetween('wf.fulfilled_at', [$from->startOfDay(), $to->endOfDay()]);
    }

    private function employeePerformance(User $user, $ids, CarbonImmutable $from, CarbonImmutable $to, bool $canRevenue, bool $canCost, bool $canProfit): Collection
    {
        if (! $this->authorization->allows($user, WebSalesPermission::ViewAll)) {
            return collect();
        }

        $query = $this->completedQuery($ids, $from, $to)
            ->join('employees as we', 'we.id', '=', 'wo.handled_by_employee_id')
            ->groupBy('we.id', 'we.name')
            ->selectRaw('we.id AS employee_id, we.name AS employee, COUNT(DISTINCT wo.id) AS orders, SUM(wfi.quantity) AS units');
        if ($canRevenue || $canProfit) {
            $query->selectRaw('SUM(woi.line_total) AS revenue');
        }
        if ($canCost || $canProfit) {
            $query->selectRaw('SUM('.ConfiguredCogs::sql('wfi').') AS cogs');
        }

        return $query->orderByDesc('units')->get()->map(function ($row) use ($canRevenue, $canCost, $canProfit): array {
            $revenueForProfit = ($canRevenue || $canProfit) ? (string) $row->revenue : null;
            $cogs = ($canCost || $canProfit) ? (string) $row->cogs : null;

            return [
                'employee' => $row->employee, 'orders' => (int) $row->orders, 'units' => (int) $row->units,
                'revenue' => $canRevenue ? $revenueForProfit : null, 'cogs' => $canCost ? $cogs : null,
                'gross_profit' => $canProfit && $revenueForProfit !== null && $cogs !== null ? bcsub($revenueForProfit, $cogs, 2) : null,
            ];
        });
    }

    private function channelPerformance($ids, CarbonImmutable $from, CarbonImmutable $to, bool $canRevenue, bool $canProfit): Collection
    {
        $query = $this->completedQuery($ids, $from, $to)
            ->groupBy('wo.web_sales_channel')
            ->selectRaw('wo.web_sales_channel AS channel, COUNT(DISTINCT wo.id) AS orders, SUM(wfi.quantity) AS units');
        if ($canRevenue || $canProfit) {
            $query->selectRaw('SUM(woi.line_total) AS revenue');
        }
        if ($canProfit) {
            $query->selectRaw('SUM('.ConfiguredCogs::sql('wfi').') AS cogs');
        }

        $rows = $query->get()->keyBy('channel');

        return collect(WebSalesChannel::cases())->map(function (WebSalesChannel $channel) use ($rows, $canRevenue, $canProfit): array {
            $row = $rows->get($channel->value);
            $revenueForProfit = ($canRevenue || $canProfit) ? (string) ($row->revenue ?? '0.00') : null;
            $cogs = $canProfit ? (string) ($row->cogs ?? '0.0000') : null;

            return [
                'channel' => $channel->getLabel(), 'orders' => (int) ($row->orders ?? 0), 'units' => (int) ($row->units ?? 0),
                'revenue' => $canRevenue ? $revenueForProfit : null,
                'gross_profit' => $canProfit && $revenueForProfit !== null ? bcsub($revenueForProfit, $cogs, 2) : null,
            ];
        });
    }

    private function productPerformance($ids, CarbonImmutable $from, CarbonImmutable $to, bool $canRevenue, bool $canProfit): Collection
    {
        $query = $this->completedQuery($ids, $from, $to)
            ->groupBy('woi.product_id', 'woi.sku', 'woi.product_name')
            ->selectRaw('woi.product_id, woi.sku, woi.product_name, SUM(wfi.quantity) AS units');
        if ($canRevenue || $canProfit) {
            $query->selectRaw('SUM(woi.line_total) AS revenue');
        }
        if ($canProfit) {
            $query->selectRaw('SUM('.ConfiguredCogs::sql('wfi').') AS cogs');
        }

        return $query->orderByDesc('units')->limit(10)->get()->map(function ($row) use ($canRevenue, $canProfit): array {
            $revenueForProfit = ($canRevenue || $canProfit) ? (string) $row->revenue : null;
            $cogs = $canProfit ? (string) $row->cogs : null;

            return [
                'product' => $row->sku.' · '.$row->product_name, 'units' => (int) $row->units,
                'revenue' => $canRevenue ? $revenueForProfit : null,
                'gross_profit' => $canProfit && $revenueForProfit !== null ? bcsub($revenueForProfit, $cogs, 2) : null,
            ];
        });
    }
}
