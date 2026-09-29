<?php

namespace App\Enums;

enum MarketplaceOperationsPermission: string
{
    case View = 'marketplace_operations.view';
    case Manage = 'marketplace_operations.manage';
}
