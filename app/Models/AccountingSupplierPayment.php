<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountingSupplierPayment extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payment_date' => 'date', 'amount' => 'decimal:2'];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(AccountingBook::class, 'accounting_book_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(AccountingSupplierPaymentAllocation::class, 'payment_id');
    }
}
