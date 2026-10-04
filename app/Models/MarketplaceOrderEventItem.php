<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceOrderEventItem extends Model
{
    protected $fillable = ['marketplace_order_event_id', 'product_id', 'listing_id', 'external_sku', 'title', 'product_condition', 'quantity'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(MarketplaceOrderEvent::class, 'marketplace_order_event_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(ProductMarketplaceListing::class, 'listing_id');
    }
}
