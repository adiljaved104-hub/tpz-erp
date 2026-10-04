<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceOrderEvent extends Model
{
    protected $fillable = ['marketplace_account_id', 'external_order_id', 'detected_at', 'source'];

    protected function casts(): array
    {
        return ['detected_at' => 'immutable_datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MarketplaceAccount::class, 'marketplace_account_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MarketplaceOrderEventItem::class);
    }
}
