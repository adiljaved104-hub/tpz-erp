<?php

namespace App\Enums;

enum MarketplaceConnectionType: string
{
    case Api = 'api';
    case Browser = 'browser';
    case Email = 'email';
    case Feed = 'feed';
}
