<?php

namespace App\Models;

use App\Enums\EmployeeLoanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeLoan extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => EmployeeLoanStatus::class];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(OfficeFinanceTransaction::class, 'loan_transaction_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(EmployeeLoanRepayment::class);
    }
}
