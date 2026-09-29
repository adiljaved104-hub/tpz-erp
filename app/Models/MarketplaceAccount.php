<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceAccount extends Model
{
    protected $fillable = ['marketplace_platform_id', 'name', 'code', 'product_condition', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(MarketplacePlatform::class, 'marketplace_platform_id');
    }

    public function connections(): HasMany
    {
        return $this->hasMany(MarketplaceConnection::class)->orderBy('priority')->orderBy('id');
    }

    public function listings(): HasMany
    {
        return $this->hasMany(ProductMarketplaceListing::class);
    }

    public function orderEvents(): HasMany
    {
        return $this->hasMany(MarketplaceOrderEvent::class);
    }
}
