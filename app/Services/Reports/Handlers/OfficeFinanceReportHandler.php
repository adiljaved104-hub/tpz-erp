<?php

namespace App\Services\Reports\Handlers;

use App\Contracts\Reports\ReportHandler;
use App\DTOs\Reports\ReportDefinition;
use App\DTOs\Reports\ReportResult;
use App\Enums\OfficeFinanceExpenseCategory;
use App\Enums\OfficeFinancePermission;
use App\Enums\OfficeFinanceTransactionType;
use App\Models\User;
use App\Services\Authorization\OfficeFinanceAuthorization;
use App\Services\Reports\ReportQueryService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OfficeFinanceReportHandler implements ReportHandler
{
    public function __construct(private readonly OfficeFinanceAuthorization $authorization) {}

    public function run(User $user, ReportDefinition $definition, array $filters, int $limit): ReportResult
    {
        [$query, $columns, $moneyColumns] = match ($definition->key) {
            'office_finance.loans_outstanding' => $this->loansOutstanding($filters),
            'office_finance.summary' => $this->summary($filters),
            default => $this->transactions($user, $definition->key, $filters),
        };
        $total = (clone $query)->reorder()->count();
        if ($limit > ReportQueryService::PREVIEW_LIMIT && $total > ReportQueryService::EXPORT_LIMIT) {
            throw ValidationException::withMessages(['export' => 'This export contains more than '.number_format(ReportQueryService::EXPORT_LIMIT).' rows. Narrow the filters and try again.']);
        }
        $rows = (clone $query)->limit(min($limit, ReportQueryService::EXPORT_LIMIT))->get()->map(function (object $row): array {
            $data = (array) $row;
            if (isset($data['type'])) {
                $data['type'] = OfficeFinanceTransactionType::tryFrom($data['type'])?->label() ?? $data['type'];
            }
            if (isset($data['category']) && $data['category'] !== null) {
                $data['category'] = OfficeFinanceExpenseCategory::tryFrom($data['category'])?->label() ?? $data['category'];
            }
            if (isset($data['status'])) {
                $data['status'] = str((string) $data['status'])->replace('_', ' ')->headline()->toString();
            }

            return $data;
        });
        $summary = ['Rows' => $total];
        foreach ($moneyColumns as $label => $column) {
            $summary[$label] = (float) DB::query()->fromSub((clone $query)->reorder(), 'office_finance_report')->sum($column);
        }

        return new ReportResult($definition->key, $definition->title, $columns, $rows, $summary, $filters, $total);
    }

    private function transactions(User $user, string $key, array $f): array
    {
        $query = DB::table('office_finance_transactions as oft')->join('office_finance_accounts as ofa', 'ofa.id', '=', 'oft.office_finance_account_id')->leftJoin('employees as e', 'e.id', '=', 'oft.employee_id')->leftJoin('users as u', 'u.id', '=', 'oft.created_by_user_id')
            ->whereBetween('oft.transaction_date', [$f['from'], $f['to']])
            ->when($f['status'], fn (Builder $q) => $q->where('oft.status', $f['status']))
            ->when($f['category'], fn (Builder $q) => $q->where('oft.category', $f['category']))
            ->when($f['employee_id'], fn (Builder $q) => $q->where('oft.employee_id', $f['employee_id']))
            ->when($f['office_account_id'] ?? null, fn (Builder $q) => $q->where('oft.office_finance_account_id', $f['office_account_id']))
            ->when(! $this->authorization->allows($user, OfficeFinancePermission::ViewAll), fn (Builder $q) => $q->where('oft.created_by_user_id', $user->id));
        if (in_array($key, ['office_finance.cashbook', 'office_finance.quickbooks'], true)) {
            $query->when(! $this->authorization->allows($user, OfficeFinancePermission::ViewFunding), fn (Builder $q) => $q->where('oft.transaction_type', '!=', 'funding_received'))
                ->when(! $this->authorization->allows($user, OfficeFinancePermission::ViewLoans), fn (Builder $q) => $q->whereNotIn('oft.transaction_type', ['employee_loan_given', 'employee_loan_repayment']));
        }
        $query = match ($key) {
            'office_finance.expenses' => $query->where('oft.transaction_type', 'expense'),
            'office_finance.funding' => $query->where('oft.transaction_type', 'funding_received'),
            'office_finance.loan_history' => $query->whereIn('oft.transaction_type', ['employee_loan_given', 'employee_loan_repayment']),
            default => $query,
        };
        $query->select([
            'oft.transaction_date as date', 'oft.reference', 'oft.transaction_type as type', 'oft.category', 'oft.description', 'ofa.name as account', 'e.name as employee',
            'oft.funding_source', 'oft.aed_amount', 'oft.exchange_rate_pkr_per_aed as exchange_rate', 'oft.calculated_pkr_amount as calculated_pkr',
            'oft.amount_pkr as actual_pkr', 'oft.status', 'u.name as created_by', 'oft.external_reference',
        ])->selectRaw("CASE WHEN oft.direction = 'in' THEN oft.amount_pkr ELSE 0 END AS money_in")
            ->selectRaw("CASE WHEN oft.direction = 'out' THEN oft.amount_pkr ELSE 0 END AS money_out")
            ->orderByDesc('oft.transaction_date')->orderByDesc('oft.id');
        $columns = $this->columns([
            'date' => 'Date', 'reference' => 'Reference', 'type' => 'Type', 'category' => 'Category', 'description' => 'Description', 'account' => 'Account', 'employee' => 'Employee',
            'funding_source' => 'Funding Source', 'aed_amount' => 'AED Amount', 'exchange_rate' => 'Rate PKR/AED', 'calculated_pkr' => 'Calculated PKR', 'actual_pkr' => 'Actual / PKR Amount',
            'money_in' => 'Money In', 'money_out' => 'Money Out', 'status' => 'Status', 'external_reference' => 'External Reference', 'created_by' => 'Created By',
        ], ['aed_amount' => 'money_aed', 'exchange_rate' => 'number', 'calculated_pkr' => 'money_pkr', 'actual_pkr' => 'money_pkr', 'money_in' => 'money_pkr', 'money_out' => 'money_pkr']);

        return [$query, $columns, ['Money In' => 'money_in', 'Money Out' => 'money_out']];
    }

    private function loansOutstanding(array $f): array
    {
        $query = DB::table('employee_loans as el')->join('employees as e', 'e.id', '=', 'el.employee_id')->join('office_finance_transactions as original', 'original.id', '=', 'el.loan_transaction_id')
            ->where('el.status', '!=', 'voided')->whereDate('original.transaction_date', '<=', $f['to'])
            ->when($f['employee_id'], fn (Builder $q) => $q->where('el.employee_id', $f['employee_id']))
            ->select(['original.reference', 'e.employee_id as employee_reference', 'e.name as employee', 'original.transaction_date as loan_date', 'original.amount_pkr as original_loan', 'el.status'])
            ->selectRaw("COALESCE((SELECT SUM(repay.amount_pkr) FROM employee_loan_repayments elr JOIN office_finance_transactions repay ON repay.id = elr.repayment_transaction_id WHERE elr.employee_loan_id = el.id AND repay.status = 'posted' AND repay.transaction_date <= ?),0) AS repaid", [$f['to']])
            ->selectRaw("original.amount_pkr - COALESCE((SELECT SUM(repay.amount_pkr) FROM employee_loan_repayments elr JOIN office_finance_transactions repay ON repay.id = elr.repayment_transaction_id WHERE elr.employee_loan_id = el.id AND repay.status = 'posted' AND repay.transaction_date <= ?),0) AS outstanding", [$f['to']])
            ->orderByDesc('loan_date');

        return [$query, $this->columns(['reference' => 'Reference', 'employee_reference' => 'Employee ID', 'employee' => 'Employee', 'loan_date' => 'Loan Date', 'original_loan' => 'Original Loan', 'repaid' => 'Repaid', 'outstanding' => 'Outstanding', 'status' => 'Status'], ['original_loan' => 'money_pkr', 'repaid' => 'money_pkr', 'outstanding' => 'money_pkr']), ['Outstanding' => 'outstanding']];
    }

    private function summary(array $f): array
    {
        $query = DB::table('office_finance_transactions as oft')->where('oft.status', 'posted')->whereBetween('oft.transaction_date', [$f['from'], $f['to']])->when($f['office_account_id'] ?? null, fn (Builder $q) => $q->where('oft.office_finance_account_id', $f['office_account_id']))
            ->select('oft.transaction_type as type')->selectRaw('COUNT(*) AS entries')->selectRaw("SUM(CASE WHEN oft.direction = 'in' THEN oft.amount_pkr ELSE 0 END) AS money_in")->selectRaw("SUM(CASE WHEN oft.direction = 'out' THEN oft.amount_pkr ELSE 0 END) AS money_out")->groupBy('oft.transaction_type')->orderBy('oft.transaction_type');

        return [$query, $this->columns(['type' => 'Transaction Type', 'entries' => 'Entries', 'money_in' => 'Money In', 'money_out' => 'Money Out'], ['entries' => 'number', 'money_in' => 'money_pkr', 'money_out' => 'money_pkr']), ['Money In' => 'money_in', 'Money Out' => 'money_out']];
    }

    private function columns(array $labels, array $types = []): array
    {
        return collect($labels)->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label] + (isset($types[$key]) ? ['type' => $types[$key]] : []))->values()->all();
    }
}
