<?php

namespace App\Services\Hr;

use App\Enums\HrPermission;
use App\Models\AttendancePolicy;
use App\Models\LeavePolicy;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\HrAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class HrPolicyService
{
    public function __construct(private readonly HrAuthorization $authorization, private readonly ActivityLogger $activity) {}

    /** @param array<string, mixed> $data */
    public function createVersion(array $data, User $actor): array
    {
        throw_unless($this->authorization->allows($actor, HrPermission::AttendanceManagePolicy)
            && $this->authorization->allows($actor, HrPermission::LeaveManagePolicy), AuthorizationException::class);
        $validated = Validator::make($data, [
            'effective_from' => ['required', 'date', 'after:today'],
            'office_start_time' => ['required', 'date_format:H:i'],
            'office_end_time' => ['required', 'date_format:H:i', 'after:office_start_time'],
            'grace_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'late_occurrences_for_penalty' => ['required', 'integer', 'min:1', 'max:100'],
            'absence_equivalent_penalty_days' => ['required', 'numeric', 'gt:0', 'max:99'],
            'half_day_minimum_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'annual_leave_entitlement_days' => ['required', 'numeric', 'min:0', 'max:366'],
            'manager_approval_enabled' => ['required', 'boolean'],
            'half_day_leave_enabled' => ['required', 'boolean'],
            'compensatory_off_requires_approval' => ['required', 'boolean'],
            'compensatory_off_expiry_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ])->validate();
        $effective = CarbonImmutable::parse($validated['effective_from'])->startOfDay();
        if (AttendancePolicy::query()->whereDate('effective_from', '>=', $effective)->exists()
            || LeavePolicy::query()->whereDate('effective_from', '>=', $effective)->exists()) {
            throw ValidationException::withMessages(['effective_from' => 'A policy version already starts on or after this date. Review future policy versions first.']);
        }

        return DB::transaction(function () use ($validated, $effective, $actor): array {
            $attendance = AttendancePolicy::query()->where('status', true)->whereNull('effective_to')->lockForUpdate()->latest('effective_from')->firstOrFail();
            $leave = LeavePolicy::query()->where('status', true)->whereNull('effective_to')->lockForUpdate()->latest('effective_from')->firstOrFail();
            $ends = $effective->subDay()->toDateString();
            $attendance->forceFill(['effective_to' => $ends])->save();
            $leave->forceFill(['effective_to' => $ends])->save();

            $newAttendance = AttendancePolicy::query()->create([
                'name' => 'Pakistan Office Attendance Policy',
                'office_start_time' => $validated['office_start_time'].':00',
                'office_end_time' => $validated['office_end_time'].':00',
                'grace_minutes' => $validated['grace_minutes'],
                'late_after_minutes' => $validated['grace_minutes'] + 1,
                'late_occurrences_for_penalty' => $validated['late_occurrences_for_penalty'],
                'absence_equivalent_penalty_days' => $validated['absence_equivalent_penalty_days'],
                'half_day_minimum_minutes' => $validated['half_day_minimum_minutes'],
                'full_day_minimum_minutes' => $attendance->full_day_minimum_minutes,
                'absence_rule' => $attendance->absence_rule,
                'effective_from' => $effective->toDateString(),
                'status' => true,
                'created_by_user_id' => $actor->id,
            ]);
            $newLeave = LeavePolicy::query()->create([
                'name' => 'Pakistan Calendar-Year Leave Policy',
                'annual_leave_entitlement_days' => $validated['annual_leave_entitlement_days'],
                'leave_year_mode' => 'calendar_year',
                'half_day_leave_enabled' => $validated['half_day_leave_enabled'],
                'manager_approval_enabled' => $validated['manager_approval_enabled'],
                'compensatory_off_requires_approval' => $validated['compensatory_off_requires_approval'],
                'compensatory_off_expiry_days' => $validated['compensatory_off_expiry_days'],
                'scheduled_sunday_compensatory_off_enabled' => $leave->scheduled_sunday_compensatory_off_enabled,
                'effective_from' => $effective->toDateString(),
                'status' => true,
                'created_by_user_id' => $actor->id,
            ]);
            $this->activity->log('hr.policy_version_created', $actor, $newAttendance, [
                'effective_from' => $effective->toDateString(),
                'changed_fields' => array_keys($validated),
                'leave_policy_id' => $newLeave->id,
            ]);

            return [$newAttendance, $newLeave];
        });
    }
}
