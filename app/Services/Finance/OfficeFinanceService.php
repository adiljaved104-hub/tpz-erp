<?php

namespace App\Services\Finance;

use App\Enums\EmployeeLoanStatus;
use App\Enums\EmployeeRole;
use App\Enums\OfficeFinanceAccountType;
use App\Enums\OfficeFinanceExpenseCategory;
use App\Enums\OfficeFinancePermission;
use App\Enums\OfficeFinanceTransactionType;
use App\Models\EmployeeLoan;
use App\Models\EmployeeLoanRepayment;
use App\Models\OfficeFinanceAccount;
use App\Models\OfficeFinanceTransaction;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\OfficeFinanceAuthorization;
use App\Services\ReferenceSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OfficeFinanceService
{
    public function __construct(
        private readonly OfficeFinanceAuthorization $authorization,
        private readonly ReferenceSequenceService $references,
        private readonly ActivityLogger $activity,
    ) {}

    public function createAccount(array $data, User $actor): OfficeFinanceAccount
    {
        $this->authorization->authorize($actor, OfficeFinancePermission::Update);
        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:120'],
            'account_type' => ['required', Rule::enum(OfficeFinanceAccountType::class)],
            'active' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string', 'max:500'],
        ])->validate();
        $normalized = mb_strtolower(trim($validated['name']));
        if (OfficeFinanceAccount::query()->where('normalized_name', $normalized)->exists()) {
            throw ValidationException::withMessages(['name' => 'An Office Account with this name already exists.']);
        }

        return DB::transaction(function () use ($validated, $normalized, $actor): OfficeFinanceAccount {
            $account = OfficeFinanceAccount::query()->create([
                'name' => trim($validated['name']),
                'normalized_name' => $normalized,
                'account_type' => $validated['account_type'] instanceof OfficeFinanceAccountType ? $validated['account_type'] : OfficeFinanceAccountType::from($validated['account_type']),
                'currency' => 'PKR',
                'active' => $validated['active'] ?? true,
                'description' => filled($validated['description'] ?? null) ? trim($validated['description']) : null,
                'created_by_user_id' => $actor->id,
            ]);
            $this->activity->log('office_finance.account_created', $actor, $account, ['changed_fields' => ['name', 'account_type', 'active']]);

            return $account;
        });
    }

    public function updateAccount(OfficeFinanceAccount $account, array $data, User $actor): OfficeFinanceAccount
    {
        $this->authorization->authorize($actor, OfficeFinancePermission::Update);
        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:120'],
            'account_type' => ['required', Rule::enum(OfficeFinanceAccountType::class)],
            'active' => ['required', 'boolean'],
            'description' => ['nullable', 'string', 'max:500'],
        ])->validate();
        $normalized = mb_strtolower(trim($validated['name']));
        if (OfficeFinanceAccount::query()->where('normalized_name', $normalized)->whereKeyNot($account->id)->exists()) {
            throw ValidationException::withMessages(['name' => 'An Office Account with this name already exists.']);
        }

        return DB::transaction(function () use ($account, $validated, $normalized, $actor): OfficeFinanceAccount {
            $account->fill([
                'name' => trim($validated['name']), 'normalized_name' => $normalized,
                'account_type' => $validated['account_type'] instanceof OfficeFinanceAccountType ? $validated['account_type'] : OfficeFinanceAccountType::from($validated['account_type']),
                'active' => $validated['active'],
                'description' => filled($validated['description'] ?? null) ? trim($validated['description']) : null,
                'updated_by_user_id' => $actor->id,
            ]);
            $changed = array_keys($account->getDirty());
            $account->save();
            if ($changed !== []) {
                $this->activity->log('office_finance.account_updated', $actor, $account, ['changed_fields' => $changed]);
            }

            return $account->refresh();
        });
    }

    public function postExpense(array $data, User $actor): OfficeFinanceTransaction
    {
        $this->authorization->authorize($actor, OfficeFinancePermission::Create);
        $validated = $this->validateCommon($data, [
            'category' => ['required', Rule::enum(OfficeFinanceExpenseCategory::class)],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        return $this->post($validated, OfficeFinanceTransactionType::Expense, 'out', $actor, [
            'category' => $validated['category'] instanceof OfficeFinanceExpenseCategory ? $validated['category'] : OfficeFinanceExpenseCategory::from($validated['category']),
            'employee_id' => ($validated['employee_id'] ?? null) ?: null,
        ]);
    }

    public function postFunding(array $data, User $actor): OfficeFinanceTransaction
    {
        $this->authorization->authorize($actor, OfficeFinancePermission::Create);
        $this->authorization->authorize($actor, OfficeFinancePermission::ViewFunding);
        $validated = $this->validateCommon($data, [
            'funding_source' => ['required', 'string', 'max:190'],
            'aed_amount' => ['required', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'exchange_rate_pkr_per_aed' => ['required', 'regex:/^\d+(?:\.\d{1,6})?$/'],
            'fx_bank_charges_pkr' => ['nullable', 'regex:/^\d+(?:\.\d{1,2})?$/'],
        ]);
        $aed = $this->positive($validated['aed_amount'], 2, 'aed_amount', 'AED Amount');
        $rate = $this->positive($validated['exchange_rate_pkr_per_aed'], 6, 'exchange_rate_pkr_per_aed', 'Exchange Rate');
        $calculated = bcmul($aed, $rate, 6);
        $calculated = bcadd($calculated, '0', 2);
        $charges = filled($validated['fx_bank_charges_pkr'] ?? null) ? bcadd($validated['fx_bank_charges_pkr'], '0', 2) : '0.00';

        return $this->post($validated, OfficeFinanceTransactionType::FundingReceived, 'in', $actor, [
            'funding_source' => trim($validated['funding_source']),
            'aed_amount' => $aed,
            'exchange_rate_pkr_per_aed' => $rate,
            'calculated_pkr_amount' => $calculated,
            'fx_bank_charges_pkr' => $charges,
        ]);
    }

    public function createLoan(array $data, User $actor): EmployeeLoan
    {
        $this->authorization->authorize($actor, OfficeFinancePermission::ManageLoans);
        $validated = $this->validateCommon($data, [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
        ]);
        $existing = $this->existing($validated['idempotency_key']);
        if ($existing?->loan !== null) {
            return $existing->loan;
        }
        $reference = $this->references->nextOfficeFinanceReference((int) substr($validated['transaction_date'], 0, 4));

        return DB::transaction(function () use ($validated, $reference, $actor): EmployeeLoan {
            $existing = $this->existing($validated['idempotency_key']);
            if ($existing?->loan !== null) {
                return $existing->loan;
            }
            $transaction = $this->createTransaction($validated, $reference, OfficeFinanceTransactionType::EmployeeLoanGiven, 'out', $actor, ['employee_id' => $validated['employee_id']]);
            $loan = EmployeeLoan::query()->create([
                'loan_transaction_id' => $transaction->id, 'employee_id' => $validated['employee_id'],
                'status' => EmployeeLoanStatus::Open, 'created_by_user_id' => $actor->id,
            ]);
            $this->activity->log('office_finance.loan_given', $actor, $loan, ['reference' => $reference]);

            return $loan->load(['transaction.account', 'employee']);
        });
    }

    public function recordRepayment(EmployeeLoan $loan, array $data, User $actor): EmployeeLoanRepayment
    {
        $this->authorization->authorize($actor, OfficeFinancePermission::ManageLoans);
        $validated = $this->validateCommon($data);
        if ($existing = EmployeeLoanRepayment::query()->where('idempotency_key', $validated['idempotency_key'])->first()) {
            return $existing;
        }
        $reference = $this->references->nextOfficeFinanceReference((int) substr($validated['transaction_date'], 0, 4));

        return DB::transaction(function () use ($loan, $validated, $reference, $actor): EmployeeLoanRepayment {
            $loan = EmployeeLoan::query()->with('transaction')->lockForUpdate()->findOrFail($loan->id);
            if ($loan->status === EmployeeLoanStatus::Voided) {
                throw ValidationException::withMessages(['loan' => 'A voided loan cannot receive repayments.']);
            }
            $outstanding = $this->outstanding($loan);
            if (bccomp($validated['amount_pkr'], $outstanding, 2) === 1) {
                throw ValidationException::withMessages(['amount_pkr' => 'Repayment cannot exceed the outstanding balance of PKR '.number_format((float) $outstanding, 2).'.']);
            }
            $transaction = $this->createTransaction($validated + ['employee_id' => $loan->employee_id], $reference, OfficeFinanceTransactionType::EmployeeLoanRepayment, 'in', $actor, ['employee_id' => $loan->employee_id]);
            $repayment = EmployeeLoanRepayment::query()->create([
                'employee_loan_id' => $loan->id, 'repayment_transaction_id' => $transaction->id,
                'idempotency_key' => $validated['idempotency_key'], 'recorded_by_user_id' => $actor->id,
            ]);
            $newOutstanding = bcsub($outstanding, $validated['amount_pkr'], 2);
            $loan->forceFill([
                'status' => bccomp($newOutstanding, '0.00', 2) === 0 ? EmployeeLoanStatus::Repaid : EmployeeLoanStatus::PartiallyRepaid,
                'updated_by_user_id' => $actor->id,
            ])->save();
            $this->activity->log('office_finance.loan_repayment_recorded', $actor, $repayment, ['reference' => $reference]);

            return $repayment->load('transaction');
        });
    }

    public function postAdjustment(array $data, User $actor): OfficeFinanceTransaction
    {
        $this->authorization->authorize($actor, OfficeFinancePermission::Create);
        if ($actor->employee?->role !== EmployeeRole::Owner) {
            throw ValidationException::withMessages(['transaction_type' => 'Only the Owner may post Office Finance adjustments.']);
        }
        $validated = $this->validateCommon($data, [
            'direction' => ['required', Rule::in(['in', 'out'])],
            'adjustment_reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        return $this->post($validated, OfficeFinanceTransactionType::Adjustment, $validated['direction'], $actor, ['adjustment_reason' => trim($validated['adjustment_reason'])]);
    }

    public function void(OfficeFinanceTransaction $transaction, string $reason, User $actor): OfficeFinanceTransaction
    {
        $this->authorization->authorize($actor, OfficeFinancePermission::Void);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['reason' => 'Enter a void reason between 5 and 2,000 characters.']);
        }

        return DB::transaction(function () use ($transaction, $reason, $actor): OfficeFinanceTransaction {
            $transaction = OfficeFinanceTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            if ($transaction->status === 'voided') {
                return $transaction;
            }
            $transaction->forceFill(['status' => 'voided', 'voided_at' => now(), 'voided_by_user_id' => $actor->id, 'void_reason' => $reason])->save();
            if ($loan = EmployeeLoan::query()->where('loan_transaction_id', $transaction->id)->first()) {
                $loan->forceFill(['status' => EmployeeLoanStatus::Voided, 'updated_by_user_id' => $actor->id])->save();
            }
            if ($repayment = EmployeeLoanRepayment::query()->where('repayment_transaction_id', $transaction->id)->first()) {
                $this->refreshLoanStatus(EmployeeLoan::query()->lockForUpdate()->findOrFail($repayment->employee_loan_id), $actor);
            }
            $this->activity->log('office_finance.transaction_voided', $actor, $transaction, ['reference' => $transaction->reference, 'reason' => $reason]);

            return $transaction->refresh();
        });
    }

    public function outstanding(EmployeeLoan $loan): string
    {
        $original = (string) $loan->transaction()->value('amount_pkr');
        $repaid = (string) OfficeFinanceTransaction::query()
            ->whereIn('id', $loan->repayments()->select('repayment_transaction_id'))
            ->where('status', 'posted')->sum('amount_pkr');

        return bcsub($original ?: '0.00', $repaid ?: '0.00', 2);
    }

    private function post(array $validated, OfficeFinanceTransactionType $type, string $direction, User $actor, array $extra): OfficeFinanceTransaction
    {
        if ($existing = $this->existing($validated['idempotency_key'])) {
            return $existing;
        }
        $reference = $this->references->nextOfficeFinanceReference((int) substr($validated['transaction_date'], 0, 4));

        return DB::transaction(function () use ($validated, $type, $direction, $actor, $extra, $reference): OfficeFinanceTransaction {
            if ($existing = $this->existing($validated['idempotency_key'])) {
                return $existing;
            }
            $transaction = $this->createTransaction($validated, $reference, $type, $direction, $actor, $extra);
            $this->activity->log('office_finance.transaction_posted', $actor, $transaction, ['reference' => $reference, 'transaction_type' => $type->value]);

            return $transaction->load(['account', 'employee']);
        });
    }

    private function createTransaction(array $validated, string $reference, OfficeFinanceTransactionType $type, string $direction, User $actor, array $extra): OfficeFinanceTransaction
    {
        $account = OfficeFinanceAccount::query()->whereKey($validated['office_finance_account_id'])->where('active', true)->lockForUpdate()->first();
        if (! $account) {
            throw ValidationException::withMessages(['office_finance_account_id' => 'Select an active Office Account.']);
        }

        return OfficeFinanceTransaction::query()->create([
            'reference' => $reference, 'transaction_date' => $validated['transaction_date'],
            'transaction_type' => $type, 'direction' => $direction,
            'description' => trim($validated['description']), 'amount_pkr' => $validated['amount_pkr'], 'currency' => 'PKR',
            'office_finance_account_id' => $account->id,
            'external_reference' => filled($validated['external_reference'] ?? null) ? trim($validated['external_reference']) : null,
            'note' => filled($validated['note'] ?? null) ? trim($validated['note']) : null,
            'status' => 'posted', 'idempotency_key' => $validated['idempotency_key'], 'created_by_user_id' => $actor->id,
        ] + $extra);
    }

    private function validateCommon(array $data, array $extra = []): array
    {
        $validated = Validator::make($data, [
            'transaction_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:500'],
            'amount_pkr' => ['required', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'office_finance_account_id' => ['required', 'integer', 'exists:office_finance_accounts,id'],
            'external_reference' => ['nullable', 'string', 'max:190'],
            'note' => ['nullable', 'string', 'max:5000'],
            'idempotency_key' => ['required', 'uuid'],
        ] + $extra)->validate();
        $validated['amount_pkr'] = $this->positive($validated['amount_pkr'], 2, 'amount_pkr', 'Amount PKR');

        return $validated;
    }

    private function positive(string $value, int $scale, string $field, string $label): string
    {
        $value = bcadd(trim($value), '0', $scale);
        if (bccomp($value, str_pad('0.', $scale + 2, '0'), $scale) <= 0) {
            throw ValidationException::withMessages([$field => "{$label} must be greater than zero."]);
        }

        return $value;
    }

    private function existing(string $idempotencyKey): ?OfficeFinanceTransaction
    {
        return OfficeFinanceTransaction::query()->with('loan')->where('idempotency_key', $idempotencyKey)->first();
    }

    private function refreshLoanStatus(EmployeeLoan $loan, User $actor): void
    {
        if ($loan->status === EmployeeLoanStatus::Voided) {
            return;
        }
        $outstanding = $this->outstanding($loan);
        $original = (string) $loan->transaction()->value('amount_pkr');
        $loan->forceFill([
            'status' => bccomp($outstanding, $original, 2) === 0 ? EmployeeLoanStatus::Open : (bccomp($outstanding, '0.00', 2) === 0 ? EmployeeLoanStatus::Repaid : EmployeeLoanStatus::PartiallyRepaid),
            'updated_by_user_id' => $actor->id,
        ])->save();
    }
}
