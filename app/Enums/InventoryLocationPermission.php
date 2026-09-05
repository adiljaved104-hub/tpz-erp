<?php

namespace App\Enums;

enum InventoryLocationPermission: string
{
    case View = 'inventory_location.view';
    case Manage = 'inventory_location.manage';
}
