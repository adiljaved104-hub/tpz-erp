<?php

namespace App\Services\Finance;

use App\Enums\OfficeFinancePermission;
use App\Models\OfficeFinanceAccount;
use App\Models\OfficeFinanceTransaction;
use App\Models\User;
use App\Services\Authorization\OfficeFinanceAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OfficeFinanceDashboardService
{
    public function __construct(private readonly OfficeFinanceAuthorization $authorization) {}

    public function range(string $period, ?string $from = null, ?string $to = null): array
    {
        $today = CarbonImmutable::today(config('app.timezone'));
        [$start, $end] = match ($period) {
            'today' => [$today, $today],
            'yesterday' => [$today->subDay(), $today->subDay()],
            'week' => [$today->startOfWeek(), $today->endOfWeek()],
            'month' => [$today->startOfMonth(), $today->endOfMonth()],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            'custom' => [CarbonImmutable::parse($from), CarbonImmutable::parse($to)],
            default => throw ValidationException::withMessages(['period' => 'Select a valid dashboard period.']),
        };
        if ($end->lt($start) || $start->diffInDays($end) > 366) {
            throw ValidationException::withMessages(['to' => 'The selected period must be valid and cannot exceed 366 days.']);
        }

        return [$start, $end];
    }

    public function metrics(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $this->authorization->authorize($user, OfficeFinancePermission::View);
        $canBalances = $this->authorization->allows($user, OfficeFinancePermission::ViewBalances);
        $canFunding = $this->authorization->allows($user, OfficeFinancePermission::ViewFunding);
        $canLoans = $this->authorization->allows($user, OfficeFinancePermission::ViewLoans);
        $base = fn () => OfficeFinanceTransaction::query()->where('status', 'posted')->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()]);

        $fundingPkr = $canFunding ? (string) $base()->where('transaction_type', 'funding_received')->sum('amount_pkr') : null;
        $fundingAed = $canFunding ? (string) $base()->where('transaction_type', 'funding_received')->sum('aed_amount') : null;
        $effectiveRate = $canFunding && bccomp($fundingAed ?: '0', '0', 2) === 1 ? bcdiv($fundingPkr, $fundingAed, 6) : null;
        $expenses = $canBalances ? (string) $base()->where('transaction_type', 'expense')->sum('amount_pkr') : null;
        $loansGiven = $canLoans ? (string) $base()->where('transaction_type', 'employee_loan_given')->sum('amount_pkr') : null;
        $repayments = $canLoans ? (string) $base()->where('transaction_type', 'employee_loan_repayment')->sum('amount_pkr') : null;

        return [
            'can_balances' => $canBalances,
            'can_funding' => $canFunding,
            'can_loans' => $canLoans,
            'summary' => [
                'funding_pkr' => $fundingPkr, 'funding_aed' => $fundingAed, 'effective_rate' => $effectiveRate,
                'expenses' => $expenses, 'loans_given' => $loansGiven, 'loan_repayments' => $repayments,
                'outstanding_loans' => $canLoans ? $this->outstandingTotal() : null,
                'available_balance' => $canBalances ? $this->totalBalance() : null,
            ],
            'accounts' => $canBalances ? $this->accountBalances() : collect(),
            'expenses_by_category' => $canBalances ? $this->expensesBy($from, $to, 'category') : collect(),
            'expenses_by_account' => $canBalances ? $this->expensesBy($from, $to, 'account') : collect(),
            'funding' => $canFunding ? $this->funding($from, $to) : collect(),
            'loans' => $canLoans ? $this->loans() : collect(),
        ];
    }

    public function totalBalance(?int $accountId = null): string
    {
        $query = OfficeFinanceTransaction::query()->where('status', 'posted')->when($accountId, fn ($query) => $query->where('office_finance_account_id', $accountId));
        $moneyIn = (string) (clone $query)->where('direction', 'in')->sum('amount_pkr');
        $moneyOut = (string) (clone $query)->where('direction', 'out')->sum('amount_pkr');

        return bcsub($moneyIn ?: '0.00', $moneyOut ?: '0.00', 2);
    }

    private function accountBalances(): Collection
    {
        return OfficeFinanceAccount::query()->orderBy('account_type')->orderBy('name')->get(['id', 'name', 'account_type', 'active'])
            ->map(fn (OfficeFinanceAccount $account): array => [
                'id' => $account->id, 'name' => $account->name, 'type' => $account->account_type->label(),
                'active' => $account->active, 'balance' => $this->totalBalance($account->id),
            ]);
    }

    private function expensesBy(CarbonImmutable $from, CarbonImmutable $to, string $group): Collection
    {
        $query = DB::table('office_finance_transactions as oft')->where('oft.status', 'posted')->where('oft.transaction_type', 'expense')->whereBetween('oft.transaction_date', [$from->toDateString(), $to->toDateString()]);
        if ($group === 'category') {
            return $query->select('oft.category as label')->selectRaw('SUM(oft.amount_pkr) AS amount')->groupBy('oft.category')->orderByDesc('amount')->get();
        }

        return $query->join('office_finance_accounts as ofa', 'ofa.id', '=', 'oft.office_finance_account_id')->select('ofa.name as label')->selectRaw('SUM(oft.amount_pkr) AS amount')->groupBy('ofa.id', 'ofa.name')->orderByDesc('amount')->get();
    }

    private function funding(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return DB::table('office_finance_transactions as oft')->join('office_finance_accounts as ofa', 'ofa.id', '=', 'oft.office_finance_account_id')
            ->where('oft.status', 'posted')->where('oft.transaction_type', 'funding_received')->whereBetween('oft.transaction_date', [$from->toDateString(), $to->toDateString()])
            ->select(['oft.transaction_date', 'oft.aed_amount', 'oft.exchange_rate_pkr_per_aed', 'oft.calculated_pkr_amount', 'oft.amount_pkr', 'ofa.name as account', 'oft.reference', 'oft.external_reference'])
            ->selectRaw('(oft.amount_pkr - oft.calculated_pkr_amount) AS difference')->orderByDesc('oft.transaction_date')->orderByDesc('oft.id')->limit(20)->get();
    }

    private function loans(): Collection
    {
        return DB::table('employee_loans as el')->join('employees as e', 'e.id', '=', 'el.employee_id')->join('office_finance_transactions as original', 'original.id', '=', 'el.loan_transaction_id')
            ->select(['el.id', 'e.name as employee', 'original.reference', 'original.amount_pkr as original_amount', 'el.status'])
            ->selectRaw("COALESCE((SELECT SUM(repay.amount_pkr) FROM employee_loan_repayments elr JOIN office_finance_transactions repay ON repay.id = elr.repayment_transaction_id WHERE elr.employee_loan_id = el.id AND repay.status = 'posted'), 0) AS repaid")
            ->selectRaw("original.amount_pkr - COALESCE((SELECT SUM(repay.amount_pkr) FROM employee_loan_repayments elr JOIN office_finance_transactions repay ON repay.id = elr.repayment_transaction_id WHERE elr.employee_loan_id = el.id AND repay.status = 'posted'), 0) AS outstanding")
            ->where('el.status', '!=', 'voided')->orderByDesc('original.transaction_date')->limit(50)->get();
    }

    private function outstandingTotal(): string
    {
        return (string) DB::query()->fromSub($this->loansBase(), 'office_loans')->sum('outstanding');
    }

    private function loansBase()
    {
        return DB::table('employee_loans as el')->join('office_finance_transactions as original', 'original.id', '=', 'el.loan_transaction_id')
            ->where('el.status', '!=', 'voided')
            ->selectRaw("original.amount_pkr - COALESCE((SELECT SUM(repay.amount_pkr) FROM employee_loan_repayments elr JOIN office_finance_transactions repay ON repay.id = elr.repayment_transaction_id WHERE elr.employee_loan_id = el.id AND repay.status = 'posted'), 0) AS outstanding");
    }
}
