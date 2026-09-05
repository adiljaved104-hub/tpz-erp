<?php

namespace App\Enums;

enum TaskCompletionSubmissionStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Returned = 'returned';
}
