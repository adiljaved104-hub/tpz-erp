<?php

namespace App\Enums;

enum InventoryAllocationPolicy: string
{
    case Automatic = 'automatic';
    case AskAtGrn = 'ask_at_grn';
    case NoAutomatic = 'no_automatic';
}
