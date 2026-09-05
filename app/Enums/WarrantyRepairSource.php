<?php

namespace App\Enums;

enum WarrantyRepairSource: string
{
    case CustomerReturn = 'customer_return';
    case DamagedItem = 'damaged_item';
    case Order = 'order';
    case Complaint = 'complaint';
    case Manual = 'manual';
}
