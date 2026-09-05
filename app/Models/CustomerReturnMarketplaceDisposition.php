<?php

namespace App\Models;

use App\Enums\MarketplaceDispositionResult;
use App\Exceptions\ImmutableInventoryRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerReturnMarketplaceDisposition extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['result' => MarketplaceDispositionResult::class, 'quantity' => 'integer', 'disposed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new ImmutableInventoryRecordException('Marketplace dispositions are immutable.'));
        static::deleting(fn (): never => throw new ImmutableInventoryRecordException('Marketplace dispositions are immutable.'));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(CustomerReturnItem::class, 'customer_return_item_id');
    }
}
