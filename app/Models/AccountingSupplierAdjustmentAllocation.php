<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingSupplierAdjustmentAllocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(AccountingSupplierAdjustment::class, 'adjustment_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(AccountingSupplierBill::class, 'bill_id');
    }
}
