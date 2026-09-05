<?php

namespace App\Enums;

enum InventorySection: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Damaged = 'damaged';
}
