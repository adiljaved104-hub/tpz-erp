<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeavePolicy extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'annual_leave_entitlement_days' => 'decimal:2',
            'half_day_leave_enabled' => 'boolean',
            'manager_approval_enabled' => 'boolean',
            'compensatory_off_requires_approval' => 'boolean',
            'scheduled_sunday_compensatory_off_enabled' => 'boolean',
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'status' => 'boolean',
        ];
    }
}
