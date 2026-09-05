<?php

namespace App\Models;

use App\Exceptions\ImmutableInventoryRecordException;
use Database\Factories\OpeningStockEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class OpeningStockEntry extends Model
{
    /** @use HasFactory<OpeningStockEntryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'available_quantity' => 'integer',
            'damaged_quantity' => 'integer',
            'unit_cost' => 'decimal:4',
            'posted_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new ImmutableInventoryRecordException('Opening Stock entries cannot be updated.'));
        static::deleting(fn (): never => throw new ImmutableInventoryRecordException('Opening Stock entries cannot be deleted.'));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'source');
    }
}
