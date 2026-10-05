<?php

namespace App\Enums;

enum SerializedUnitStatus: string
{
    case Received = 'received';
    case QcPending = 'qc_pending';
    case QcInProgress = 'qc_in_progress';
    case QcPassed = 'qc_passed';
    case QcFailed = 'qc_failed';
    case RepairRequired = 'repair_required';
    case Allocated = 'allocated';
    case Sold = 'sold';
    case Returned = 'returned';
}
