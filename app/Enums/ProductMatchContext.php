<?php

namespace App\Enums;

enum ProductMatchContext: string
{
    case Order = 'order';
    case Quotation = 'quotation';
    case Purchase = 'purchase';
    case Receiving = 'receiving';
    case ProductCreation = 'product_creation';
    case ProductUpdate = 'product_update';
    case WebSales = 'web_sales';

    public function allowsComponents(): bool
    {
        return in_array($this, [self::Purchase, self::Receiving], true);
    }

    public function requiresSellableStock(): bool
    {
        return in_array($this, [self::Order, self::WebSales], true);
    }
}
