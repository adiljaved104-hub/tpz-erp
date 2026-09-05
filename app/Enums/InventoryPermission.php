<?php

namespace App\Enums;

enum InventoryPermission: string
{
    case View = 'inventory.view';
    case ViewFinancials = 'inventory.view_financials';
    case PostOpeningStock = 'inventory.post_opening_stock';
    case ReverseOpeningStock = 'inventory.reverse_opening_stock';
    case Reserve = 'inventory.reserve';
    case ReleaseReservation = 'inventory.release_reservation';
    case MarkDamaged = 'inventory.mark_damaged';
    case RestoreDamaged = 'inventory.restore_damaged';
    case ViewMovements = 'inventory.view_movements';
    case Export = 'inventory.export';
}
