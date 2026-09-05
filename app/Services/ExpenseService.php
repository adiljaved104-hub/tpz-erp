<?php

namespace App\Services;

use App\Enums\ExpenseCategory;
use App\Enums\ExpenseCostCenter;
use App\Enums\ExpensePermission;
use App\Models\Expense;
use App\Models\User;
use App\Services\Authorization\ExpenseAuthorization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExpenseService
{
    public function __construct(
        private readonly ExpenseAuthorization $authorization,
        private readonly ActivityLogger $activity,
    ) {}

    public function create(array $data, User $actor): Expense
    {
        $this->authorization->authorize($actor, ExpensePermission::Create);
        $this->authorization->authorize($actor, ExpensePermission::ViewAmount);

        return DB::transaction(function () use ($data, $actor): Expense {
            $expense = Expense::query()->create($this->normalized($data) + ['created_by_user_id' => $actor->id]);
            $this->activity->log('expense.created', $actor, $expense, ['changed_fields' => array_keys($this->normalized($data))]);

            return $expense;
        });
    }

    public function update(Expense $expense, array $data, User $actor): Expense
    {
        $this->authorization->authorize($actor, ExpensePermission::Update);
        $this->authorization->authorize($actor, ExpensePermission::ViewAmount);
        if ($expense->voided_at !== null) {
            throw ValidationException::withMessages(['expense' => 'A voided expense cannot be edited.']);
        }

        return DB::transaction(function () use ($expense, $data, $actor): Expense {
            $expense->fill($this->normalized($data) + ['updated_by_user_id' => $actor->id]);
            $changed = array_keys($expense->getDirty());
            $expense->save();
            if ($changed !== []) {
                $this->activity->log('expense.updated', $actor, $expense, ['changed_fields' => $changed]);
            }

            return $expense->refresh();
        });
    }

    public function void(Expense $expense, string $reason, User $actor): Expense
    {
        $this->authorization->authorize($actor, ExpensePermission::DeleteOrVoid);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Enter a void reason between 5 and 500 characters.']);
        }
        if ($expense->voided_at !== null) {
            return $expense;
        }

        return DB::transaction(function () use ($expense, $reason, $actor): Expense {
            $expense->forceFill(['voided_at' => now(), 'voided_by_user_id' => $actor->id, 'void_reason' => $reason, 'updated_by_user_id' => $actor->id])->save();
            $this->activity->log('expense.voided', $actor, $expense, ['changed_fields' => ['voided_at', 'voided_by_user_id', 'void_reason']]);

            return $expense->refresh();
        });
    }

    private function normalized(array $data): array
    {
        $data['category'] = $data['category'] instanceof ExpenseCategory ? $data['category']->value : ($data['category'] ?? null);
        $data['cost_center'] = $data['cost_center'] instanceof ExpenseCostCenter ? $data['cost_center']->value : ($data['cost_center'] ?? null);
        $data = Validator::make($data, [
            'expense_date' => ['required', 'date'],
            'category' => ['required', Rule::enum(ExpenseCategory::class)],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'cost_center' => ['required', Rule::enum(ExpenseCostCenter::class)],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'reference_note' => ['nullable', 'string', 'max:500'],
        ], ['amount.regex' => 'Enter a valid AED amount with no more than two decimal places.'])->validate();

        $rawAmount = trim((string) $data['amount']);
        $amount = bcadd($rawAmount, '0', 2);
        if (bccomp($amount, '0.00', 2) <= 0) {
            throw ValidationException::withMessages(['amount' => 'The expense amount must be greater than zero.']);
        }

        return [
            'expense_date' => $data['expense_date'],
            'category' => ExpenseCategory::from($data['category']),
            'description' => trim((string) $data['description']),
            'amount' => $amount,
            'cost_center' => ExpenseCostCenter::from($data['cost_center']),
            'employee_id' => $data['employee_id'] ?: null,
            'reference_note' => filled($data['reference_note'] ?? null) ? trim((string) $data['reference_note']) : null,
        ];
    }
}
