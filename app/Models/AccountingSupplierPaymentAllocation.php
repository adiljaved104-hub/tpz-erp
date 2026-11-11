<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingSupplierPaymentAllocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(AccountingSupplierPayment::class, 'payment_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(AccountingSupplierBill::class, 'bill_id');
    }
}
