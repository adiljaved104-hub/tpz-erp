<?php

namespace App\Enums;

enum MarketplaceDispositionResult: string
{
    case Sellable = 'sellable';
    case NonSellable = 'non_sellable';
}
