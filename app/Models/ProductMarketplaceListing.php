<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductMarketplaceListing extends Model
{
    protected $fillable = ['product_id', 'marketplace_platform_id', 'marketplace_identifier', 'listing_sku', 'listing_title', 'monitor_enabled', 'monitor_source'];

    protected function casts(): array
    {
        return [
            'monitor_enabled' => 'boolean',
            'last_checked_at' => 'immutable_datetime',
            'last_successful_observation_at' => 'immutable_datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(MarketplacePlatform::class, 'marketplace_platform_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(MarketplaceMonitorObservation::class, 'listing_id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(MarketplaceOperationIncident::class, 'listing_id');
    }
}
