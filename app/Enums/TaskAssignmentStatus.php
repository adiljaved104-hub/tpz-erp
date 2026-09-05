<?php

namespace App\Enums;

enum TaskAssignmentStatus: string
{
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Waiting = 'waiting';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Removed = 'removed';

    public function isActive(): bool
    {
        return in_array($this, [self::Assigned, self::InProgress, self::Waiting], true);
    }
}
