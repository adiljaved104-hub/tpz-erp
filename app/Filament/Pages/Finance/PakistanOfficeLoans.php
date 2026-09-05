<?php

namespace App\Filament\Pages\Finance;

use App\Enums\OfficeFinancePermission;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\OfficeFinanceAccount;
use App\Services\Finance\OfficeFinanceService;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class PakistanOfficeLoans extends BaseOfficeFinancePage
{
    protected string $view = 'filament.pages.finance.pakistan-office-loans';

    protected static ?string $slug = 'finance/pakistan-office/loans';

    protected static ?string $navigationLabel = 'Employee Loans';

    protected static ?string $navigationParentItem = 'Pakistan Office Finance';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 14;

    protected static OfficeFinancePermission $requiredPermission = OfficeFinancePermission::ViewLoans;

    public string $employeeId = '';

    public string $loanDate = '';

    public string $loanAmount = '';

    public string $loanReason = '';

    public string $loanAccountId = '';

    public string $loanExternalReference = '';

    public string $loanNote = '';

    public string $loanKey = '';

    public string $repaymentLoanId = '';

    public string $repaymentDate = '';

    public string $repaymentAmount = '';

    public string $repaymentAccountId = '';

    public string $repaymentReference = '';

    public string $repaymentNote = '';

    public string $repaymentKey = '';

    public function mount(): void
    {
        parent::mount();
        $this->resetLoanForm();
        $this->resetRepaymentForm();
    }

    public function createLoan(): void
    {
        try {
            app(OfficeFinanceService::class)->createLoan([
                'transaction_date' => $this->loanDate, 'description' => $this->loanReason,
                'amount_pkr' => $this->loanAmount, 'office_finance_account_id' => $this->loanAccountId,
                'employee_id' => $this->employeeId, 'external_reference' => $this->loanExternalReference ?: null,
                'note' => $this->loanNote ?: null, 'idempotency_key' => $this->loanKey,
            ], $this->user());
            Notification::make()->success()->title('Employee loan posted')->send();
            $this->resetLoanForm();
        } catch (ValidationException $exception) {
            $this->showErrors($exception, 'loan');
        }
    }

    public function prepareRepayment(int $loanId): void
    {
        EmployeeLoan::query()->whereNotIn('status', ['repaid', 'voided'])->findOrFail($loanId);
        $this->repaymentLoanId = (string) $loanId;
    }

    public function recordRepayment(): void
    {
        try {
            $loan = EmployeeLoan::query()->findOrFail((int) $this->repaymentLoanId);
            app(OfficeFinanceService::class)->recordRepayment($loan, [
                'transaction_date' => $this->repaymentDate, 'description' => 'Employee loan repayment',
                'amount_pkr' => $this->repaymentAmount, 'office_finance_account_id' => $this->repaymentAccountId,
                'external_reference' => $this->repaymentReference ?: null, 'note' => $this->repaymentNote ?: null,
                'idempotency_key' => $this->repaymentKey,
            ], $this->user());
            Notification::make()->success()->title('Loan repayment recorded')->send();
            $this->resetRepaymentForm();
        } catch (ValidationException $exception) {
            $this->showErrors($exception, 'repayment');
        }
    }

    public function getViewData(): array
    {
        $loans = EmployeeLoan::query()->with(['employee:id,name,employee_id', 'transaction.account:id,name', 'repayments.transaction'])->latest()->paginate(25);
        $loans->getCollection()->each(function (EmployeeLoan $loan): void {
            $loan->setAttribute('repaid_amount', $loan->repayments->where('transaction.status', 'posted')->sum(fn ($row): float => (float) $row->transaction->amount_pkr));
            $loan->setAttribute('outstanding_amount', app(OfficeFinanceService::class)->outstanding($loan));
        });

        return [
            'loans' => $loans,
            'employees' => Employee::query()->where('status', true)->orderBy('name')->get(['id', 'name', 'employee_id']),
            'accounts' => OfficeFinanceAccount::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'canManage' => $this->allows(OfficeFinancePermission::ManageLoans),
        ];
    }

    private function resetLoanForm(): void
    {
        $this->reset('employeeId', 'loanAmount', 'loanReason', 'loanAccountId', 'loanExternalReference', 'loanNote');
        $this->loanDate = today()->toDateString();
        $this->loanKey = (string) str()->uuid();
        $this->resetValidation();
    }

    private function resetRepaymentForm(): void
    {
        $this->reset('repaymentLoanId', 'repaymentAmount', 'repaymentAccountId', 'repaymentReference', 'repaymentNote');
        $this->repaymentDate = today()->toDateString();
        $this->repaymentKey = (string) str()->uuid();
        $this->resetValidation();
    }

    private function showErrors(ValidationException $exception, string $prefix): void
    {
        foreach ($exception->errors() as $field => $messages) {
            $this->addError($prefix.str($field)->studly()->toString(), $messages[0]);
        }
    }
}
