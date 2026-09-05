<?php

namespace App\Enums;

enum TaskEventType: string
{
    case Created = 'created';
    case Assigned = 'assigned';
    case Reassigned = 'reassigned';
    case Started = 'started';
    case StatusChanged = 'status_changed';
    case Waiting = 'waiting';
    case Resumed = 'resumed';
    case DueDateChanged = 'due_date_changed';
    case PriorityChanged = 'priority_changed';
    case CommentAdded = 'comment_added';
    case Completed = 'completed';
    case Reopened = 'reopened';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }
}
