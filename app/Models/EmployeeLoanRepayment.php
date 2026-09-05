<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeLoanRepayment extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(EmployeeLoan::class, 'employee_loan_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(OfficeFinanceTransaction::class, 'repayment_transaction_id');
    }
}
