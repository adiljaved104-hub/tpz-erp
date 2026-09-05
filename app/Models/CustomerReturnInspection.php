<?php

namespace App\Models;

use App\Enums\CustomerReturnInspectionResult;
use App\Exceptions\ImmutableInventoryRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CustomerReturnInspection extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'result' => CustomerReturnInspectionResult::class, 'inspected_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new ImmutableInventoryRecordException('Return inspections are immutable.'));
        static::deleting(fn (): never => throw new ImmutableInventoryRecordException('Return inspections are immutable.'));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(CustomerReturnItem::class, 'customer_return_item_id');
    }

    public function inspectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by_user_id');
    }

    public function removalItem(): BelongsTo
    {
        return $this->belongsTo(MarketplaceReturnRemovalItem::class, 'marketplace_return_removal_item_id');
    }

    public function damagedStockEvent(): HasOne
    {
        return $this->hasOne(DamagedStockEvent::class);
    }
}
