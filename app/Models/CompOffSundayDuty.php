<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompOffSundayDuty extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['duty_date' => 'immutable_date', 'authorized_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime', 'verified_minutes' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(EmployeeAttendance::class, 'employee_attendance_id');
    }
}
