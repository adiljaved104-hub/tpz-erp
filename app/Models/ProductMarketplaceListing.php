<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class ProductMarketplaceListing extends Model
{
    protected $fillable = ['product_id', 'marketplace_platform_id', 'marketplace_account_id', 'marketplace_identifier', 'listing_sku', 'listing_title', 'direct_url', 'monitor_enabled', 'monitor_source'];

    protected function casts(): array
    {
        return [
            'monitor_enabled' => 'boolean',
            'last_checked_at' => 'immutable_datetime',
            'last_successful_observation_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $listing): void {
            if ($listing->marketplace_account_id === null) {
                return;
            }
            $platformId = MarketplaceAccount::query()->whereKey($listing->marketplace_account_id)->value('marketplace_platform_id');
            if ((int) $platformId !== (int) $listing->marketplace_platform_id) {
                throw ValidationException::withMessages(['marketplace_account_id' => 'The marketplace account must belong to the selected platform.']);
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(MarketplacePlatform::class, 'marketplace_platform_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MarketplaceAccount::class, 'marketplace_account_id');
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
