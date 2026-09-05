<?php

namespace App\Enums;

enum InventoryItemType: string
{
    case Product = 'product';
    case Component = 'component';
}
