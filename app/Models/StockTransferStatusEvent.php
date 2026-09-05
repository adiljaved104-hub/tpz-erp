<?php

namespace App\Models;

use App\Enums\StockTransferStatus;
use App\Exceptions\InvalidStockTransferTransitionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferStatusEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['from_status' => StockTransferStatus::class, 'to_status' => StockTransferStatus::class, 'context' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new InvalidStockTransferTransitionException('Transfer status history is immutable.'));
        static::deleting(fn (): never => throw new InvalidStockTransferTransitionException('Transfer status history is immutable.'));
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
