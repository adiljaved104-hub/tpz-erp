<?php

namespace App\Enums;

enum QcInspectionStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Rework = 'rework_required';
    case Completed = 'completed';
}
