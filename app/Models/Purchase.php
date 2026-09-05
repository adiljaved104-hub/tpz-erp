<?php

namespace App\Models;

use App\Enums\PurchaseEntryType;
use App\Enums\PurchaseStatus;
use App\Exceptions\ImmutablePurchaseException;
use Database\Factories\PurchaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Purchase extends Model
{
    /** @use HasFactory<PurchaseFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => PurchaseStatus::class,
            'entry_type' => PurchaseEntryType::class,
            'purchase_date' => 'date',
            'expected_delivery_date' => 'date',
            'supplier_invoice_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'net_before_vat' => 'decimal:2',
            'shipping_total' => 'decimal:2',
            'shipping_vat_rate' => 'decimal:2',
            'shipping_vat_amount' => 'decimal:2',
            'other_charges_total' => 'decimal:2',
            'other_charges_vat_rate' => 'decimal:2',
            'other_charges_vat_amount' => 'decimal:2',
            'vat_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'self_approved' => 'boolean',
            'approved_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new ImmutablePurchaseException('Purchases cannot be deleted.'));
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'handled_by_employee_id');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class);
    }

    public function hasReceipts(): bool
    {
        return $this->relationLoaded('receipts') ? $this->receipts->isNotEmpty() : $this->receipts()->exists();
    }
}
