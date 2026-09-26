<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductMarketplaceListing extends Model
{
    protected $fillable = ['product_id', 'marketplace_platform_id', 'marketplace_identifier', 'listing_sku', 'listing_title'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(MarketplacePlatform::class, 'marketplace_platform_id');
    }
}
