<?php

namespace App\Enums;

enum MarketplaceConnectionCapability: string
{
    case FeaturedOffer = 'featured_offer';
    case Orders = 'orders';
    case ListingStatus = 'listing_status';
    case StockStatus = 'stock_status';
    case DirectProductCheck = 'direct_product_check';
    case ProductSearch = 'product_search';
    case EventWebhook = 'event_webhook';
}
