<?php

namespace App\Services\Reports\Handlers;

use App\Contracts\Reports\ReportHandler;
use App\DTOs\Reports\ReportDefinition;
use App\DTOs\Reports\ReportResult;
use App\Enums\ExpenseCategory;
use App\Enums\ExpenseCostCenter;
use App\Enums\ExpensePermission;
use App\Models\User;
use App\Services\Authorization\ExpenseAuthorization;
use App\Services\Reports\ReportQueryService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpenseReportHandler implements ReportHandler
{
    public function __construct(private readonly ExpenseAuthorization $authorization) {}

    public function run(User $user, ReportDefinition $definition, array $filters, int $limit): ReportResult
    {
        $canAmount = $this->authorization->allows($user, ExpensePermission::ViewAmount);
        $query = DB::table('expenses as ex')
            ->leftJoin('employees as ee', 'ee.id', '=', 'ex.employee_id')
            ->whereBetween('ex.expense_date', [$filters['from'], $filters['to']])
            ->when($filters['category'], fn (Builder $query) => $query->where('ex.category', $filters['category']))
            ->when($filters['cost_center'], fn (Builder $query) => $query->where('ex.cost_center', $filters['cost_center']))
            ->when($filters['employee_id'], fn (Builder $query) => $query->where('ex.employee_id', $filters['employee_id']))
            ->when($filters['status'] === 'active', fn (Builder $query) => $query->whereNull('ex.voided_at'))
            ->when($filters['status'] === 'voided', fn (Builder $query) => $query->whereNotNull('ex.voided_at'));

        [$query, $columns, $summaryColumn] = match ($definition->key) {
            'finance.expenses' => $this->detailed($query, $canAmount),
            'finance.expenses_by_category' => $this->byCategory($query, $canAmount),
            'finance.expenses_by_cost_center' => $this->byCostCenter($query, $canAmount),
        };

        $total = (clone $query)->reorder()->count();
        if ($limit > ReportQueryService::PREVIEW_LIMIT && $total > ReportQueryService::EXPORT_LIMIT) {
            throw ValidationException::withMessages(['export' => 'This export contains more than '.number_format(ReportQueryService::EXPORT_LIMIT).' rows. Narrow the filters and try again.']);
        }
        $rows = (clone $query)->limit(min($limit, ReportQueryService::EXPORT_LIMIT))->get()->map(function (object $row): array {
            $data = (array) $row;
            if (isset($data['category'])) {
                $data['category'] = ExpenseCategory::from($data['category'])->label();
            }
            if (isset($data['cost_center'])) {
                $data['cost_center'] = ExpenseCostCenter::from($data['cost_center'])->label();
            }

            return $data;
        });
        $summary = ['Rows' => $total];
        if ($summaryColumn !== null) {
            $summary['Total Expenses'] = (float) DB::query()->fromSub((clone $query)->reorder(), 'expense_report_summary')->sum($summaryColumn);
        }

        return new ReportResult($definition->key, $definition->title, $columns, $rows, $summary, $filters, $total);
    }

    private function detailed(Builder $query, bool $canAmount): array
    {
        $query->select(['ex.expense_date', 'ex.category', 'ex.description', 'ex.cost_center', 'ee.name as employee', 'ex.reference_note'])
            ->selectRaw("CASE WHEN ex.voided_at IS NULL THEN 'Active' ELSE 'Voided' END AS status")
            ->orderByDesc('ex.expense_date')->orderByDesc('ex.id');
        $columns = $this->columns([
            'expense_date' => 'Date', 'category' => 'Category', 'description' => 'Description',
            'cost_center' => 'Cost Center', 'employee' => 'Employee', 'reference_note' => 'Reference / Note', 'status' => 'Status',
        ]);
        if ($canAmount) {
            $query->addSelect('ex.amount');
            array_splice($columns, 3, 0, [[
                'key' => 'amount', 'label' => 'Amount', 'type' => 'money',
            ]]);
        }

        return [$query, $columns, $canAmount ? 'amount' : null];
    }

    private function byCategory(Builder $query, bool $canAmount): array
    {
        $query->select('ex.category')->selectRaw('COUNT(*) AS expense_count')->groupBy('ex.category')->orderByDesc('expense_count');
        $columns = $this->columns(['category' => 'Category', 'expense_count' => 'Expense Count'], ['expense_count']);
        if ($canAmount) {
            $query->selectRaw('SUM(ex.amount) AS amount');
            $columns[] = ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'];
        }

        return [$query, $columns, $canAmount ? 'amount' : null];
    }

    private function byCostCenter(Builder $query, bool $canAmount): array
    {
        $query->select('ex.cost_center')->selectRaw('COUNT(*) AS expense_count')->groupBy('ex.cost_center')->orderByDesc('expense_count');
        $columns = $this->columns(['cost_center' => 'Cost Center', 'expense_count' => 'Expense Count'], ['expense_count']);
        if ($canAmount) {
            $query->selectRaw('SUM(ex.amount) AS amount');
            $columns[] = ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'];
        }

        return [$query, $columns, $canAmount ? 'amount' : null];
    }

    /** @param array<string, string> $labels @param array<int, string> $numeric */
    private function columns(array $labels, array $numeric = []): array
    {
        return collect($labels)->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label] + (in_array($key, $numeric, true) ? ['type' => 'number'] : []))->values()->all();
    }
}
