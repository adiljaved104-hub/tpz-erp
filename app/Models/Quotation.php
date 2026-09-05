<?php

namespace App\Models;

use App\Enums\QuotationDocumentType;
use App\Enums\QuotationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Quotation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'document_type' => QuotationDocumentType::class,
            'status' => QuotationStatus::class,
            'quotation_date' => 'immutable_date',
            'valid_until' => 'immutable_date',
            'seller_snapshot' => 'array',
            'subtotal_excluding_vat' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'sent_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'converted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $quotation): void {
            if ($quotation->getRawOriginal('status') === QuotationStatus::Draft->value) {
                return;
            }

            $workflowFields = [
                'status', 'sent_at', 'accepted_at', 'rejected_at', 'converted_at', 'cancelled_at',
                'order_id', 'tax_invoice_id', 'order_conversion_idempotency_key',
                'invoice_conversion_idempotency_key', 'updated_at',
            ];
            if (array_diff(array_keys($quotation->getDirty()), $workflowFields) !== []) {
                throw new LogicException('Sent or converted Quotation commercial content is immutable.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Quotations cannot be hard-deleted.'));
    }

    public function scopeEffectivelyExpired(Builder $query): Builder
    {
        return $query->whereIn('status', [QuotationStatus::Draft->value, QuotationStatus::Sent->value])
            ->whereDate('valid_until', '<', today());
    }

    public function effectiveStatus(): QuotationStatus
    {
        if (in_array($this->status, [QuotationStatus::Draft, QuotationStatus::Sent], true) && $this->valid_until->isBefore(today())) {
            return QuotationStatus::Expired;
        }

        return $this->status;
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('line_number');
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'salesperson_employee_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function taxInvoice(): BelongsTo
    {
        return $this->belongsTo(TaxInvoice::class);
    }

    public function emailDeliveries(): HasMany
    {
        return $this->hasMany(QuotationEmailDelivery::class)->latest('requested_at');
    }
}
