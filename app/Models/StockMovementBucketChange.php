<?php

namespace App\Models;

use App\Enums\StockMovementBucket;
use App\Exceptions\ImmutableInventoryRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovementBucketChange extends Model
{
    protected $guarded = [];

    protected $hidden = ['value_delta', 'value_before', 'value_after'];

    protected function casts(): array
    {
        return [
            'bucket' => StockMovementBucket::class,
            'quantity_delta' => 'integer',
            'quantity_before' => 'integer',
            'quantity_after' => 'integer',
            'value_delta' => 'decimal:4',
            'value_before' => 'decimal:4',
            'value_after' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new ImmutableInventoryRecordException('Stock Movement bucket changes cannot be updated.'));
        static::deleting(fn (): never => throw new ImmutableInventoryRecordException('Stock Movement bucket changes cannot be deleted.'));
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }
}
