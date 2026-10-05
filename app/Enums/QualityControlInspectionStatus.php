<?php

namespace App\Enums;

enum QualityControlInspectionStatus: string
{
    case Draft = 'draft';
    case InProgress = 'in_progress';
    case Passed = 'passed';
    case Failed = 'failed';
    case RepairRequired = 'repair_required';
}
