<?php

namespace App\Models;

use App\Enums\SafetClaimSource;
use App\Enums\SafetClaimStatus;
use App\Exceptions\SafetClaimException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SafetClaim extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'source' => SafetClaimSource::class, 'status' => SafetClaimStatus::class,
            'filing_due_at' => 'immutable_datetime', 'filed_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime', 'rejected_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime',
            'claimed_amount' => 'decimal:2', 'approved_amount' => 'decimal:2', 'reimbursed_amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new SafetClaimException('Claims cannot be deleted.'));
    }

    public function scopeNeedsFiling(Builder $query): Builder
    {
        return $query->where('status', SafetClaimStatus::NeedsFiling->value);
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(MarketplacePlatform::class, 'marketplace_platform_id');
    }

    public function customerReturn(): BelongsTo
    {
        return $this->belongsTo(CustomerReturn::class);
    }

    public function returnItem(): BelongsTo
    {
        return $this->belongsTo(CustomerReturnItem::class, 'customer_return_item_id');
    }

    public function damagedStockEvent(): BelongsTo
    {
        return $this->belongsTo(DamagedStockEvent::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function fulfillmentItem(): BelongsTo
    {
        return $this->belongsTo(OrderFulfillmentItem::class, 'order_fulfillment_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(SafetClaimStatusEvent::class)->orderBy('changed_at')->orderBy('id');
    }
}
