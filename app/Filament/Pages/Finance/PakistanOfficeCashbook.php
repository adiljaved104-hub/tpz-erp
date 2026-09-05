<?php

namespace App\Filament\Pages\Finance;

use App\Enums\EmployeeRole;
use App\Enums\OfficeFinanceExpenseCategory;
use App\Enums\OfficeFinancePermission;
use App\Enums\OfficeFinanceTransactionType;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\OfficeFinanceAccount;
use App\Models\OfficeFinanceTransaction;
use App\Services\Finance\OfficeFinanceService;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Livewire\WithPagination;

class PakistanOfficeCashbook extends BaseOfficeFinancePage
{
    use WithPagination;

    protected string $view = 'filament.pages.finance.pakistan-office-cashbook';

    protected static ?string $slug = 'finance/pakistan-office/cashbook';

    protected static ?string $navigationLabel = 'Cashbook';

    protected static ?string $navigationParentItem = 'Pakistan Office Finance';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?int $navigationSort = 11;

    public string $period = 'month';

    public string $from = '';

    public string $to = '';

    public string $typeFilter = '';

    public string $accountFilter = '';

    public string $categoryFilter = '';

    public string $employeeFilter = '';

    public string $employeeSearch = '';

    public string $statusFilter = 'posted';

    public string $search = '';

    public string $transactionType = 'expense';

    public string $transactionDate = '';

    public string $description = '';

    public string $amountPkr = '';

    public string $accountId = '';

    public string $category = '';

    public string $employeeId = '';

    public string $externalReference = '';

    public string $note = '';

    public string $fundingSource = '';

    public string $aedAmount = '';

    public string $exchangeRate = '';

    public string $fxCharges = '';

    public string $direction = 'in';

    public string $adjustmentReason = '';

    public string $loanId = '';

    public string $idempotencyKey = '';

    public bool $expenseShortcut = false;

    public array $voidReasons = [];

    public function mount(): void
    {
        parent::mount();
        $this->transactionDate = today()->toDateString();
        $this->from = today()->startOfMonth()->toDateString();
        $this->to = today()->toDateString();
        $this->renewKey();
    }

    public function setPeriod(string $period): void
    {
        $today = today();
        [$from, $to] = match ($period) {
            'today' => [$today, $today], 'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()],
            'week' => [$today->copy()->startOfWeek(), $today->copy()->endOfWeek()],
            'month' => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()],
            default => [$this->from, $this->to],
        };
        $this->period = $period;
        if ($period !== 'custom') {
            $this->from = $from->toDateString();
            $this->to = $to->toDateString();
        }
        $this->resetPage();
    }

    public function applyFilters(): void
    {
        $this->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        $this->period = 'custom';
        $this->resetPage();
    }

    public function postTransaction(): void
    {
        $this->user();
        $data = $this->transactionData();
        try {
            match (OfficeFinanceTransactionType::from($this->transactionType)) {
                OfficeFinanceTransactionType::Expense => app(OfficeFinanceService::class)->postExpense($data + ['category' => $this->category, 'employee_id' => $this->employeeId ?: null], $this->user()),
                OfficeFinanceTransactionType::FundingReceived => app(OfficeFinanceService::class)->postFunding($data + ['funding_source' => $this->fundingSource, 'aed_amount' => $this->aedAmount, 'exchange_rate_pkr_per_aed' => $this->exchangeRate, 'fx_bank_charges_pkr' => $this->fxCharges ?: null], $this->user()),
                OfficeFinanceTransactionType::EmployeeLoanGiven => app(OfficeFinanceService::class)->createLoan($data + ['employee_id' => $this->employeeId], $this->user()),
                OfficeFinanceTransactionType::EmployeeLoanRepayment => app(OfficeFinanceService::class)->recordRepayment(EmployeeLoan::query()->findOrFail((int) $this->loanId), $data, $this->user()),
                OfficeFinanceTransactionType::Adjustment => app(OfficeFinanceService::class)->postAdjustment($data + ['direction' => $this->direction, 'adjustment_reason' => $this->adjustmentReason], $this->user()),
            };
            Notification::make()->success()->title('Office Finance transaction posted')->send();
            $this->resetTransactionForm();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError(str($field)->camel()->toString(), $messages[0]);
            }
        }
    }

    public function voidTransaction(int $id): void
    {
        try {
            app(OfficeFinanceService::class)->void(OfficeFinanceTransaction::query()->findOrFail($id), (string) ($this->voidReasons[$id] ?? ''), $this->user());
            unset($this->voidReasons[$id]);
            Notification::make()->success()->title('Transaction voided')->send();
        } catch (ValidationException $exception) {
            $this->addError('voidReasons.'.$id, collect($exception->errors())->flatten()->first());
        }
    }

    public function getCalculatedPkrProperty(): string
    {
        return is_numeric($this->aedAmount) && is_numeric($this->exchangeRate) ? bcadd(bcmul($this->aedAmount, $this->exchangeRate, 6), '0', 2) : '0.00';
    }

    public function getViewData(): array
    {
        $query = OfficeFinanceTransaction::query()->with(['account:id,name', 'employee:id,name', 'createdBy:id,name'])->whereBetween('transaction_date', [$this->from, $this->to])
            ->when(! $this->allows(OfficeFinancePermission::ViewAll), fn ($q) => $q->where('created_by_user_id', $this->user()->id))
            ->when(! $this->allows(OfficeFinancePermission::ViewFunding), fn ($q) => $q->where('transaction_type', '!=', 'funding_received'))
            ->when(! $this->allows(OfficeFinancePermission::ViewLoans), fn ($q) => $q->whereNotIn('transaction_type', ['employee_loan_given', 'employee_loan_repayment']))
            ->when($this->typeFilter, fn ($q) => $q->where('transaction_type', $this->typeFilter))
            ->when($this->accountFilter, fn ($q) => $q->where('office_finance_account_id', $this->accountFilter))
            ->when($this->categoryFilter, fn ($q) => $q->where('category', $this->categoryFilter))
            ->when($this->employeeFilter, fn ($q) => $q->where('employee_id', $this->employeeFilter))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('reference', 'like', '%'.$this->search.'%')->orWhere('description', 'like', '%'.$this->search.'%')->orWhere('funding_source', 'like', '%'.$this->search.'%')->orWhereHas('employee', fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))))
            ->latest('transaction_date')->latest('id');

        $employeeIds = collect([$this->employeeId, $this->employeeFilter])->filter()->map(fn (string $id): int => (int) $id)->all();
        $employees = Employee::query()->where('status', true)
            ->when(mb_strlen(trim($this->employeeSearch)) >= 2, fn ($query) => $query->where(function ($query): void {
                $query->where('name', 'like', '%'.trim($this->employeeSearch).'%')
                    ->orWhere('employee_id', 'like', '%'.trim($this->employeeSearch).'%');
            }))
            ->when(mb_strlen(trim($this->employeeSearch)) < 2, fn ($query) => $query->whereIn('id', $employeeIds))
            ->orderBy('name')->limit(50)->get(['id', 'name', 'employee_id']);

        return [
            'transactions' => $query->paginate(25),
            'accounts' => OfficeFinanceAccount::query()->orderBy('name')->get(['id', 'name', 'active']),
            'employees' => $employees,
            'loans' => EmployeeLoan::query()->with(['employee:id,name', 'transaction:id,reference,amount_pkr'])->whereNotIn('status', ['repaid', 'voided'])->latest()->get(),
            'types' => OfficeFinanceTransactionType::options(), 'categories' => OfficeFinanceExpenseCategory::options(),
            'canCreate' => $this->allows(OfficeFinancePermission::Create), 'canVoid' => $this->allows(OfficeFinancePermission::Void),
            'owner' => $this->user()->employee?->role === EmployeeRole::Owner,
        ];
    }

    private function transactionData(): array
    {
        return ['transaction_date' => $this->transactionDate, 'description' => $this->description, 'amount_pkr' => $this->amountPkr, 'office_finance_account_id' => $this->accountId, 'external_reference' => $this->externalReference ?: null, 'note' => $this->note ?: null, 'idempotency_key' => $this->idempotencyKey];
    }

    private function resetTransactionForm(): void
    {
        $this->reset('description', 'amountPkr', 'accountId', 'category', 'employeeId', 'externalReference', 'note', 'fundingSource', 'aedAmount', 'exchangeRate', 'fxCharges', 'adjustmentReason', 'loanId');
        $this->transactionType = 'expense';
        $this->transactionDate = today()->toDateString();
        $this->direction = 'in';
        $this->resetValidation();
        $this->renewKey();
    }

    private function renewKey(): void
    {
        $this->idempotencyKey = (string) str()->uuid();
    }
}
