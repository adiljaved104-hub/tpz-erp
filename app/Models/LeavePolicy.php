<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
            'leave_year_basis' => 'string',
            'monthly_accrual_enabled' => 'boolean',
            'carry_forward_enabled' => 'boolean',
            'maximum_carry_forward_days' => 'decimal:2',
            'carry_forward_expiry_months' => 'integer',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(LeavePolicyEvent::class, 'leave_policy_id');
    }
}
