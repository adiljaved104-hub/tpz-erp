<?php

namespace App\Models;

use App\Enums\MarketplaceReturnRemovalStatus;
use App\Exceptions\ImmutableInventoryRecordException;
use Illuminate\Database\Eloquent\Model;

class MarketplaceReturnRemovalEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['from_status' => MarketplaceReturnRemovalStatus::class, 'to_status' => MarketplaceReturnRemovalStatus::class];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new ImmutableInventoryRecordException('Marketplace Removal events are immutable.'));
        static::deleting(fn (): never => throw new ImmutableInventoryRecordException('Marketplace Removal events are immutable.'));
    }
}
