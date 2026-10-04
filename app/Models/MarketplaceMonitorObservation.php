<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceMonitorObservation extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['observed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new \LogicException('Marketplace observations are immutable.'));
        static::deleting(fn (): never => throw new \LogicException('Marketplace observations are immutable.'));
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(ProductMarketplaceListing::class, 'listing_id');
    }
}
