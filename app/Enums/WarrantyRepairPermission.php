<?php

namespace App\Enums;

enum WarrantyRepairPermission: string
{
    case View = 'warranty_repair.view';
    case Create = 'warranty_repair.create';
    case UpdateStatus = 'warranty_repair.update_status';
    case Assign = 'warranty_repair.assign';
    case Receive = 'warranty_repair.receive';
    case Inspect = 'warranty_repair.inspect';
    case MoveToDamaged = 'warranty_repair.move_to_damaged';
}
