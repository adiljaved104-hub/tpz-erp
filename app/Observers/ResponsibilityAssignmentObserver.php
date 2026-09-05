<?php

namespace App\Observers;

use App\Exceptions\ImmutableResponsibilityException;
use App\Models\ResponsibilityAssignment;

class ResponsibilityAssignmentObserver
{
    private const MUTABLE_TRANSITION_FIELDS = ['status', 'active_fingerprint', 'ended_at', 'ended_by_user_id', 'updated_at'];

    public function updating(ResponsibilityAssignment $assignment): void
    {
        $unexpected = array_diff(array_keys($assignment->getDirty()), self::MUTABLE_TRANSITION_FIELDS);

        if ($unexpected !== []) {
            throw new ImmutableResponsibilityException('Responsibility scope and history fields are immutable.');
        }
    }
}
