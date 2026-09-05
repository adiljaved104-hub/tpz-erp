<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeAttendance extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'immutable_date', 'status' => AttendanceStatus::class,
            'expected_start_at' => 'immutable_datetime', 'expected_end_at' => 'immutable_datetime',
            'first_check_in_at' => 'immutable_datetime', 'last_check_out_at' => 'immutable_datetime',
            'is_overridden' => 'boolean', 'calculated_at' => 'immutable_datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class, 'work_schedule_id');
    }

    public function hasEarlyCheckout(): bool
    {
        return (int) $this->early_departure_minutes > 0;
    }

    public function hasMissingCheckout(): bool
    {
        return $this->first_check_in_at !== null
            && $this->last_check_out_at === null
            && ! in_array($this->status, [
                AttendanceStatus::ApprovedLeave,
                AttendanceStatus::UnpaidLeave,
                AttendanceStatus::PublicHoliday,
                AttendanceStatus::WeekendOff,
                AttendanceStatus::CompensatoryOff,
            ], true);
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(AttendanceCorrection::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AttendancePolicy::class, 'attendance_policy_id');
    }
}
