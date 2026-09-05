<?php

namespace App\Enums;

enum CustomerReturnInspectionResult: string
{
    case Sellable = 'sellable';
    case Damaged = 'damaged';
}
