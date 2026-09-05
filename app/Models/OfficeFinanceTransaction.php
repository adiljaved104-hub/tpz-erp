<?php

namespace App\Models;

use App\Enums\OfficeFinanceExpenseCategory;
use App\Enums\OfficeFinanceTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OfficeFinanceTransaction extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'immutable_date',
            'transaction_type' => OfficeFinanceTransactionType::class,
            'category' => OfficeFinanceExpenseCategory::class,
            'amount_pkr' => 'decimal:2',
            'aed_amount' => 'decimal:2',
            'exchange_rate_pkr_per_aed' => 'decimal:6',
            'calculated_pkr_amount' => 'decimal:2',
            'fx_bank_charges_pkr' => 'decimal:2',
            'voided_at' => 'immutable_datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(OfficeFinanceAccount::class, 'office_finance_account_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function loan(): HasOne
    {
        return $this->hasOne(EmployeeLoan::class, 'loan_transaction_id');
    }
}
