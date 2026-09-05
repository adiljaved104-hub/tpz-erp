<?php

namespace App\Models;

use App\Enums\StockMovementType;
use App\Exceptions\ImmutableInventoryRecordException;
use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovement extends Model
{
    /** @use HasFactory<StockMovementFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'movement_type' => StockMovementType::class,
            'quantity' => 'integer',
            'available_delta' => 'integer',
            'reserved_delta' => 'integer',
            'damaged_delta' => 'integer',
            'available_before' => 'integer',
            'available_after' => 'integer',
            'reserved_before' => 'integer',
            'reserved_after' => 'integer',
            'damaged_before' => 'integer',
            'damaged_after' => 'integer',
            'unit_cost' => 'decimal:4',
            'average_cost_before' => 'decimal:4',
            'average_cost_after' => 'decimal:4',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new ImmutableInventoryRecordException('Stock Movements cannot be updated.'));
        static::deleting(fn (): never => throw new ImmutableInventoryRecordException('Stock Movements cannot be deleted.'));
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(ProductInventory::class, 'product_inventory_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function bucketChanges(): HasMany
    {
        return $this->hasMany(StockMovementBucketChange::class);
    }
}
