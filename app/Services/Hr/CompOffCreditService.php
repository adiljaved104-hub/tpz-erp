<?php

namespace App\Services\Hr;

use App\Enums\AttendanceStatus;
use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Models\AttendanceCorrection;
use App\Models\CompensatoryOff;
use App\Models\CompOffApprovalEvent;
use App\Models\CompOffCreditRequest;
use App\Models\CompOffCreditRequestDay;
use App\Models\CompOffSundayDuty;
use App\Models\CompOffWalletEntry;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\LeavePolicy;
use App\Models\LeaveRequestDay;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\HrAuthorization;
use App\Services\ReferenceSequenceService;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompOffCreditService
{
    public function __construct(
        private readonly HrAuthorization $authorization,
        private readonly HrScopeService $scope,
        private readonly EffectiveWorkScheduleResolver $schedules,
        private readonly ReferenceSequenceService $references,
        private readonly BiometricAttendanceProcessor $biometric,
        private readonly ActivityLogger $activity,
    ) {}

    /** @return array{earned: int, used: int, reserved: int, available: int, legacy_unreconciled: int} */
    public function balance(Employee $employee, User $actor): array
    {
        throw_unless($this->scope->canViewLeave($actor, $employee), AuthorizationException::class);
        $totals = CompOffWalletEntry::query()->where('employee_id', $employee->id)
            ->selectRaw('COALESCE(SUM(earned_delta), 0) AS earned, COALESCE(SUM(used_delta), 0) AS used, COALESCE(SUM(reserved_delta), 0) AS reserved')->first();
        $earned = (int) $totals->earned;
        $used = (int) $totals->used;
        $reserved = (int) $totals->reserved;

        return [
            'earned' => $earned, 'used' => $used, 'reserved' => $reserved,
            'available' => $earned - $used - $reserved,
            'legacy_unreconciled' => CompensatoryOff::query()->where('employee_id', $employee->id)
                ->whereIn('status', ['pending', 'approved', 'used'])->count(),
        ];
    }

    public function canManage(User $actor, Employee $employee): bool
    {
        return $actor->employee !== null && ! $actor->employee->is($employee)
            && in_array($actor->employee->role, [EmployeeRole::Owner, EmployeeRole::Admin, EmployeeRole::Manager], true)
            && $this->authorization->allows($actor, HrPermission::CompOffManage)
            && $this->scope->canViewAttendance($actor, $employee);
    }

    /** @return array{status: string, reason: string, ready: bool} */
    public function sundayApprovalEligibility(CompOffSundayDuty $duty): array
    {
        if ($duty->status !== 'authorized') {
            return $this->eligibility('Already Reviewed', 'This Sunday duty already has a decision.');
        }

        $date = $duty->duty_date->toDateString();
        $shiftStart = CarbonImmutable::parse($date.' '.$duty->shift_start, 'Asia/Karachi');
        $shiftEnd = CarbonImmutable::parse($date.' '.$duty->shift_end, 'Asia/Karachi');
        $now = CarbonImmutable::now('Asia/Karachi');
        $policyExists = LeavePolicy::query()->where('status', true)->where('scheduled_sunday_compensatory_off_enabled', true)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))->exists();
        $legacyConflict = CompensatoryOff::query()->where('employee_id', $duty->employee_id)
            ->whereDate('earned_work_date', $date)->where('status', '!=', 'cancelled')->exists();
        if (! $policyExists || $legacyConflict) {
            return $this->eligibility('Needs Review', 'The duty policy or an older Comp-Off record needs management review.');
        }
        if ($now->lessThan($shiftStart)) {
            return $this->eligibility('Scheduled', 'Approval available after the 11 AM–5 PM shift and verified attendance.');
        }

        $attendance = EmployeeAttendance::query()->where('employee_id', $duty->employee_id)
            ->whereDate('attendance_date', $date)->first();
        if ($attendance?->first_check_in_at === null || $attendance->last_check_out_at === null) {
            return $this->eligibility('Awaiting Attendance', 'A verified check-in and check-out are required.');
        }
        $first = $attendance->first_check_in_at->setTimezone('Asia/Karachi');
        $last = $attendance->last_check_out_at->setTimezone('Asia/Karachi');
        $hasEvidence = DB::table('attendance_evidence_links')->where('employee_attendance_id', $attendance->id)->exists()
            || AttendanceCorrection::query()->where('employee_attendance_id', $attendance->id)->exists();
        if (! $hasEvidence || $first->toDateString() !== $date || $last->toDateString() !== $date) {
            return $this->eligibility('Awaiting Attendance', 'Attendance must have auditable evidence for the authorized Sunday.');
        }
        $workedMinutes = min((int) ($attendance->worked_minutes ?? 0),
            max(0, (int) $attendance->first_check_in_at->diffInMinutes($attendance->last_check_out_at)));
        $shiftMinutes = max(0, (int) max($first, $shiftStart)->diffInMinutes(min($last, $shiftEnd), false));
        if ($workedMinutes < 360 || $shiftMinutes < 360) {
            return $this->eligibility('Insufficient Hours', 'At least 360 verified minutes during the 11 AM–5 PM shift are required.');
        }
        if ($now->lessThan($shiftEnd)) {
            return $this->eligibility('Awaiting Shift Completion', 'Approval is available after the 5 PM shift ends.');
        }

        return ['status' => 'Ready for Approval', 'reason' => 'Attendance and shift completion are verified.', 'ready' => true];
    }

    /** @return array{status: string, reason: string, ready: bool} */
    private function eligibility(string $status, string $reason): array
    {
        return compact('status', 'reason') + ['ready' => false];
    }

    /** @param array<string, mixed> $data */
    public function authorizeSunday(array $data, User $actor): CompOffSundayDuty
    {
        $values = Validator::make($data, [
            'employee_id' => 'required|integer|exists:employees,id',
            'duty_date' => 'required|date', 'reason' => 'required|string|min:5|max:2000',
            'idempotency_key' => 'required|uuid',
        ])->validate();
        $employee = Employee::query()->findOrFail($values['employee_id']);
        throw_unless($this->canManage($actor, $employee), AuthorizationException::class);
        $date = CarbonImmutable::parse($values['duty_date'], 'Asia/Karachi');
        if (! $employee->status || ! $date->isSunday() || $date->isBefore(today('Asia/Karachi'))
            || ($date->isSameDay(today('Asia/Karachi')) && now('Asia/Karachi')->format('H:i') >= '11:00')) {
            throw ValidationException::withMessages(['duty_date' => 'Choose an upcoming Sunday for an active employee.']);
        }
        if ($existing = CompOffSundayDuty::query()->where('idempotency_key', $values['idempotency_key'])->first()) {
            if ($existing->employee_id !== $employee->id || $existing->duty_date->toDateString() !== $date->toDateString()
                || $existing->reason !== trim($values['reason'])) {
                throw ValidationException::withMessages(['idempotency_key' => 'This submission key was used for a different duty.']);
            }

            return $existing;
        }
        $reference = $this->reference('sunday_duty', 'CSD');

        return DB::transaction(function () use ($employee, $date, $values, $actor, $reference): CompOffSundayDuty {
            Employee::query()->lockForUpdate()->findOrFail($employee->id);
            if ($existing = CompOffSundayDuty::query()->where('idempotency_key', $values['idempotency_key'])->first()) {
                if ($existing->employee_id !== $employee->id || $existing->duty_date->toDateString() !== $date->toDateString()
                    || $existing->reason !== trim($values['reason'])) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This submission key was used for a different duty.']);
                }

                return $existing;
            }
            if (CompOffSundayDuty::query()->where('employee_id', $employee->id)->whereDate('duty_date', $date)->exists()) {
                throw ValidationException::withMessages(['duty_date' => 'Sunday duty is already recorded for this employee and date.']);
            }
            $duty = CompOffSundayDuty::query()->create([
                'reference' => $reference, 'employee_id' => $employee->id, 'duty_date' => $date->toDateString(),
                'shift_start' => '11:00:00', 'shift_end' => '17:00:00', 'status' => 'authorized',
                'authorized_by_user_id' => $actor->id, 'reason' => trim($values['reason']),
                'authorized_at' => now(), 'idempotency_key' => $values['idempotency_key'],
            ]);
            $this->event($duty, null, 'authorized', $actor, $values['reason']);

            return $duty;
        }, 5);
    }

    public function decideSunday(CompOffSundayDuty $duty, bool $approve, User $actor, ?string $reason = null): CompOffSundayDuty
    {
        $duty->loadMissing('employee');
        throw_unless($this->canManage($actor, $duty->employee), AuthorizationException::class);
        if (! $approve) {
            Validator::make(['reason' => $reason], ['reason' => 'required|string|min:5|max:2000'])->validate();
        }
        $walletReference = $approve ? $this->reference('wallet', 'CWL') : null;

        return DB::transaction(function () use ($duty, $approve, $actor, $reason, $walletReference): CompOffSundayDuty {
            $employee = Employee::query()->lockForUpdate()->findOrFail($duty->employee_id);
            $locked = CompOffSundayDuty::query()->lockForUpdate()->findOrFail($duty->id);
            if ($locked->status === ($approve ? 'approved' : 'rejected')) {
                return $locked;
            }
            if ($locked->status !== 'authorized') {
                throw ValidationException::withMessages(['status' => 'This Sunday duty already has a decision.']);
            }
            $attendance = null;
            $minutes = null;
            if ($approve) {
                $policy = LeavePolicy::query()->where('status', true)->where('scheduled_sunday_compensatory_off_enabled', true)
                    ->whereDate('effective_from', '<=', $locked->duty_date)
                    ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $locked->duty_date))
                    ->latest('effective_from')->first();
                if ($policy === null || CompensatoryOff::query()->where('employee_id', $employee->id)
                    ->whereDate('earned_work_date', $locked->duty_date)->where('status', '!=', 'cancelled')->exists()) {
                    throw ValidationException::withMessages(['duty_date' => 'The duty is ineligible or overlaps a legacy Comp-Off record. Review it manually.']);
                }
                $attendance = EmployeeAttendance::query()->where('employee_id', $employee->id)
                    ->whereDate('attendance_date', $locked->duty_date)->lockForUpdate()->first();
                if ($attendance === null || $attendance->first_check_in_at === null || $attendance->last_check_out_at === null) {
                    throw ValidationException::withMessages(['attendance' => 'A verified check-in and check-out are required.']);
                }
                $hasEvidence = DB::table('attendance_evidence_links')->where('employee_attendance_id', $attendance->id)->exists()
                    || AttendanceCorrection::query()->where('employee_attendance_id', $attendance->id)->exists();
                $minutes = min((int) ($attendance->worked_minutes ?? 0),
                    max(0, (int) $attendance->first_check_in_at->diffInMinutes($attendance->last_check_out_at)));
                if (! $hasEvidence || $minutes < 360) {
                    throw ValidationException::withMessages(['attendance' => 'At least 360 verified working minutes and auditable attendance evidence are required.']);
                }
                $first = $attendance->first_check_in_at->setTimezone('Asia/Karachi');
                $last = $attendance->last_check_out_at->setTimezone('Asia/Karachi');
                if ($first->toDateString() !== $locked->duty_date->toDateString()
                    || $last->toDateString() !== $locked->duty_date->toDateString()) {
                    throw ValidationException::withMessages(['attendance' => 'Both verified punches must be on the authorized Sunday.']);
                }
                $shiftStart = CarbonImmutable::parse($locked->duty_date->toDateString().' '.$locked->shift_start, 'Asia/Karachi');
                $shiftEnd = CarbonImmutable::parse($locked->duty_date->toDateString().' '.$locked->shift_end, 'Asia/Karachi');
                if (now('Asia/Karachi')->lessThan($shiftEnd)) {
                    throw ValidationException::withMessages(['attendance' => 'Approve the duty only after the Sunday shift has ended.']);
                }
                $verifiedShiftMinutes = max(0, (int) max($first, $shiftStart)->diffInMinutes(min($last, $shiftEnd), false));
                if ($verifiedShiftMinutes < 360) {
                    throw ValidationException::withMessages(['attendance' => 'The authorized 11 AM–5 PM shift needs 360 verified working minutes.']);
                }
            }
            $locked->forceFill([
                'status' => $approve ? 'approved' : 'rejected', 'decided_by_user_id' => $actor->id,
                'employee_attendance_id' => $attendance?->id, 'verified_minutes' => $minutes,
                'decision_reason' => $reason, 'decided_at' => now(),
            ])->save();
            if ($approve) {
                $this->post($employee, 'earned', 1, 0, 0, $actor, 'duty:'.$locked->id.':earned', $walletReference,
                    'Approved verified Sunday duty', $locked, null);
            }
            $this->event($locked, null, $approve ? 'approved' : 'rejected', $actor, $reason);
            $this->activity->log('comp_off.sunday_decided', $actor, $locked, ['reference' => $locked->reference, 'approved' => $approve]);

            return $locked;
        }, 5);
    }

    /** @param array<string, mixed> $data */
    public function submit(array $data, User $actor): CompOffCreditRequest
    {
        throw_unless($this->authorization->allows($actor, HrPermission::LeaveRequest), AuthorizationException::class);
        $employee = $actor->employee;
        throw_unless($employee instanceof Employee, AuthorizationException::class);
        $values = Validator::make($data, [
            'from_date' => 'required|date', 'to_date' => 'required|date|after_or_equal:from_date',
            'reason' => 'required|string|min:5|max:2000', 'idempotency_key' => 'required|uuid',
        ])->validate();
        $from = CarbonImmutable::parse($values['from_date'], 'Asia/Karachi');
        $to = CarbonImmutable::parse($values['to_date'], 'Asia/Karachi');
        if ($from->isBefore(today('Asia/Karachi')) || $from->diffInDays($to) > 31) {
            throw ValidationException::withMessages(['from_date' => 'Choose future dates within a 31-day request period.']);
        }
        if ($existing = CompOffCreditRequest::query()->where('idempotency_key', $values['idempotency_key'])->first()) {
            if ($existing->employee_id !== $employee->id || $existing->from_date->toDateString() !== $from->toDateString()
                || $existing->to_date->toDateString() !== $to->toDateString() || $existing->reason !== trim($values['reason'])) {
                throw ValidationException::withMessages(['idempotency_key' => 'This submission key was used for different dates or a different reason.']);
            }

            return $existing;
        }
        $requestReference = $this->reference('request', 'COR');
        $walletReference = $this->reference('wallet', 'CWL');

        return DB::transaction(function () use ($employee, $from, $to, $values, $actor, $requestReference, $walletReference): CompOffCreditRequest {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            if (! $employee->status) {
                throw new AuthorizationException;
            }
            if ($existing = CompOffCreditRequest::query()->where('idempotency_key', $values['idempotency_key'])->first()) {
                if ($existing->employee_id !== $employee->id || $existing->from_date->toDateString() !== $from->toDateString()
                    || $existing->to_date->toDateString() !== $to->toDateString()
                    || $existing->reason !== trim($values['reason'])) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This submission key was used for different dates or a different reason.']);
                }

                return $existing;
            }
            $days = $this->days($employee, $from, $to);
            $credits = array_sum(array_column($days, 'credits_required'));
            if ($credits < 1) {
                throw ValidationException::withMessages(['from_date' => 'The selected dates contain no scheduled working day.']);
            }
            $this->assertNoConflict($employee, $from, $to);
            $request = CompOffCreditRequest::query()->create([
                'reference' => $requestReference, 'employee_id' => $employee->id,
                'from_date' => $from->toDateString(), 'to_date' => $to->toDateString(),
                'credits_required' => $credits, 'status' => 'pending', 'reason' => trim($values['reason']),
                'submitted_by_user_id' => $actor->id, 'submitted_at' => now(),
                'idempotency_key' => $values['idempotency_key'],
            ]);
            foreach ($days as $day) {
                CompOffCreditRequestDay::query()->create($day + ['comp_off_credit_request_id' => $request->id]);
            }
            $this->post($employee, 'reserved', 0, $credits, 0, $actor, 'request:'.$request->id.':reserve', $walletReference,
                'Credits reserved for pending Comp-Off request', null, $request);
            $this->event(null, $request, 'submitted', $actor);
            $this->activity->log('comp_off.request_submitted', $actor, $request, ['reference' => $requestReference, 'credits' => $credits]);

            return $request;
        }, 5);
    }

    public function decideRequest(CompOffCreditRequest $request, bool $approve, User $actor, ?string $reason = null): CompOffCreditRequest
    {
        $request->loadMissing('employee');
        throw_unless($this->canManage($actor, $request->employee), AuthorizationException::class);
        if (! $approve) {
            Validator::make(['reason' => $reason], ['reason' => 'required|string|min:5|max:2000'])->validate();
        }
        $walletReference = $this->reference('wallet', 'CWL');

        return DB::transaction(function () use ($request, $approve, $actor, $reason, $walletReference): CompOffCreditRequest {
            $employee = Employee::query()->lockForUpdate()->findOrFail($request->employee_id);
            $locked = CompOffCreditRequest::query()->with('days')->lockForUpdate()->findOrFail($request->id);
            if ($locked->status === ($approve ? 'approved' : 'rejected')) {
                return $locked;
            }
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'This request already has a decision.']);
            }
            if ($approve) {
                if ($locked->from_date->isBefore(today('Asia/Karachi'))) {
                    throw ValidationException::withMessages(['dates' => 'Past Comp-Off dates need a separate attendance reconciliation.']);
                }
                $this->assertNoConflict($employee, $locked->from_date, $locked->to_date, $locked->id);
                if ($this->days($employee, $locked->from_date, $locked->to_date) !== $locked->days->map(fn ($day): array => [
                    'off_date' => $day->off_date->toDateString(), 'work_schedule_id' => $day->work_schedule_id,
                    'classification' => $day->classification, 'credits_required' => $day->credits_required,
                ])->all()) {
                    throw ValidationException::withMessages(['dates' => 'The schedule or holiday changed. Cancel and submit a new request.']);
                }
                $this->assertAttendanceCanChange($employee, $locked);
            }
            $locked->forceFill([
                'status' => $approve ? 'approved' : 'rejected', 'decided_by_user_id' => $actor->id,
                'decision_reason' => $reason, 'decided_at' => now(),
            ])->save();
            $this->post($employee, $approve ? 'used' : 'released', 0, -$locked->credits_required,
                $approve ? $locked->credits_required : 0, $actor,
                'request:'.$locked->id.($approve ? ':use' : ':reject_release'), $walletReference,
                $approve ? 'Approved Comp-Off absence' : 'Rejected Comp-Off request', null, $locked);
            if ($approve) {
                $this->setAttendance($employee, $locked, $actor);
            }
            $this->event(null, $locked, $approve ? 'approved' : 'rejected', $actor, $reason);
            $this->activity->log('comp_off.request_decided', $actor, $locked, ['reference' => $locked->reference, 'approved' => $approve]);

            return $locked;
        }, 5);
    }

    public function cancel(CompOffCreditRequest $request, User $actor, string $reason): CompOffCreditRequest
    {
        Validator::make(['reason' => $reason], ['reason' => 'required|string|min:5|max:2000'])->validate();
        $request->loadMissing('employee');
        $ownPending = $request->status === 'pending' && $actor->employee?->is($request->employee)
            && $this->authorization->allows($actor, HrPermission::LeaveRequest);
        throw_unless($ownPending || $this->canManage($actor, $request->employee), AuthorizationException::class);
        $walletReference = $this->reference('wallet', 'CWL');

        return DB::transaction(function () use ($request, $actor, $reason, $walletReference): CompOffCreditRequest {
            $employee = Employee::query()->lockForUpdate()->findOrFail($request->employee_id);
            $locked = CompOffCreditRequest::query()->with('days')->lockForUpdate()->findOrFail($request->id);
            if ($locked->status === 'cancelled') {
                return $locked;
            }
            if (! in_array($locked->status, ['pending', 'approved'], true)) {
                throw ValidationException::withMessages(['status' => 'Only pending or approved Comp-Off can be cancelled.']);
            }
            if ($locked->status === 'approved') {
                throw_unless($this->canManage($actor, $employee), AuthorizationException::class);
                foreach ($locked->days->where('credits_required', 1) as $day) {
                    if ($day->off_date->isBefore(today('Asia/Karachi'))) {
                        throw ValidationException::withMessages(['attendance' => 'Past approved absences need a separate management reconciliation before credits can be released.']);
                    }
                    $attendance = EmployeeAttendance::query()->where('employee_id', $employee->id)
                        ->whereDate('attendance_date', $day->off_date)->lockForUpdate()->first();
                    if ($attendance?->status !== AttendanceStatus::CompensatoryOff || $attendance->is_overridden) {
                        throw ValidationException::withMessages(['attendance' => 'Approved absence has changed; reconcile attendance before releasing credits.']);
                    }
                }
            }
            $wasApproved = $locked->status === 'approved';
            $locked->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'decision_reason' => $reason])->save();
            $this->post($employee, $wasApproved ? 'use_reversed' : 'released', 0,
                $wasApproved ? 0 : -$locked->credits_required, $wasApproved ? -$locked->credits_required : 0,
                $actor, 'request:'.$locked->id.($wasApproved ? ':reverse' : ':cancel_release'), $walletReference,
                $reason, null, $locked);
            if ($wasApproved) {
                foreach ($locked->days->where('credits_required', 1) as $day) {
                    $attendance = EmployeeAttendance::query()->where('employee_id', $employee->id)
                        ->whereDate('attendance_date', $day->off_date)->firstOrFail();
                    $before = $attendance->status->value;
                    $updated = $this->biometric->rebuild($employee, CarbonImmutable::parse($day->off_date));
                    $this->attendanceAudit($updated, $actor, $locked, $before);
                }
            }
            $this->event(null, $locked, 'cancelled', $actor, $reason);
            $this->activity->log('comp_off.request_cancelled', $actor, $locked, ['reference' => $locked->reference, 'approved_reversal' => $wasApproved]);

            return $locked;
        }, 5);
    }

    /** @return array<int, array{off_date: string, work_schedule_id: int, classification: string, credits_required: int}> */
    private function days(Employee $employee, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = [];
        foreach (CarbonPeriod::create($from, $to) as $raw) {
            $date = CarbonImmutable::instance($raw);
            $resolved = $this->schedules->day($employee, $date);
            $holiday = PublicHoliday::query()->where('status', true)->whereDate('holiday_date', $date)->exists();
            $working = $resolved['day']->is_working_day && ! $holiday;
            $days[] = [
                'off_date' => $date->toDateString(), 'work_schedule_id' => $resolved['assignment']->work_schedule_id,
                'classification' => $holiday ? 'public_holiday' : ($working ? 'working_day' : 'weekly_off'),
                'credits_required' => $working ? 1 : 0,
            ];
        }

        return $days;
    }

    private function assertNoConflict(Employee $employee, CarbonImmutable $from, CarbonImmutable $to, ?int $exceptRequestId = null): void
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();
        $newRequest = CompOffCreditRequestDay::query()->whereBetween('off_date', [$fromDate, $toDate])
            ->whereHas('request', fn ($query) => $query->where('employee_id', $employee->id)
                ->whereIn('status', ['pending', 'approved'])
                ->when($exceptRequestId !== null, fn ($query) => $query->whereKeyNot($exceptRequestId)))->exists();
        $legacy = CompensatoryOff::query()->where('employee_id', $employee->id)
            ->whereIn('status', ['pending', 'approved', 'used'])->whereBetween('off_date', [$fromDate, $toDate])->exists();
        $leave = LeaveRequestDay::query()->whereBetween('leave_date', [$fromDate, $toDate])
            ->whereHas('request', fn ($query) => $query->where('employee_id', $employee->id)
                ->whereIn('status', ['pending', 'approved']))->exists();
        if ($newRequest || $legacy || $leave) {
            throw ValidationException::withMessages(['from_date' => 'These dates overlap leave or another Comp-Off request.']);
        }
    }

    private function assertAttendanceCanChange(Employee $employee, CompOffCreditRequest $request): void
    {
        foreach ($request->days->where('credits_required', 1) as $day) {
            $attendance = EmployeeAttendance::query()->where('employee_id', $employee->id)
                ->whereDate('attendance_date', $day->off_date)->lockForUpdate()->first();
            if ($attendance !== null && ($attendance->is_overridden || $attendance->status !== AttendanceStatus::Absent
                || $attendance->first_check_in_at !== null || $attendance->last_check_out_at !== null)) {
                throw ValidationException::withMessages(['attendance' => 'Attendance already records work or an approved absence on a selected date. Review it before approval.']);
            }
        }
    }

    private function setAttendance(Employee $employee, CompOffCreditRequest $request, User $actor): void
    {
        foreach ($request->days->where('credits_required', 1) as $day) {
            $attendance = EmployeeAttendance::query()->where('employee_id', $employee->id)
                ->whereDate('attendance_date', $day->off_date)->lockForUpdate()->first();
            $before = $attendance?->status?->value;
            if ($attendance === null) {
                $attendance = EmployeeAttendance::query()->create([
                    'employee_id' => $employee->id, 'attendance_date' => $day->off_date->toDateString(),
                    'work_schedule_id' => $day->work_schedule_id, 'status' => AttendanceStatus::CompensatoryOff,
                    'source' => 'system_derived', 'is_overridden' => false, 'calculated_at' => now(),
                ]);
            } else {
                $attendance->forceFill(['status' => AttendanceStatus::CompensatoryOff, 'calculated_at' => now()])->save();
            }
            if ($before !== null) {
                $this->attendanceAudit($attendance, $actor, $request, $before);
            }
        }
    }

    private function attendanceAudit(EmployeeAttendance $attendance, User $actor, CompOffCreditRequest $request, string $before): void
    {
        if ($before === $attendance->status->value) {
            return;
        }
        AttendanceCorrection::query()->create([
            'employee_attendance_id' => $attendance->id, 'actor_user_id' => $actor->id,
            'reason' => 'Comp-Off request '.$request->reference.' changed attendance status.',
            'old_values' => ['status' => $before], 'new_values' => ['status' => $attendance->status->value],
            'idempotency_key' => (string) Str::uuid(), 'occurred_at' => now(), 'created_at' => now(),
        ]);
    }

    private function post(Employee $employee, string $kind, int $earned, int $reserved, int $used, User $actor,
        string $operationKey, string $reference, string $reason, ?CompOffSundayDuty $duty, ?CompOffCreditRequest $request): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Comp-Off wallet posting requires a transaction and employee lock.');
        }
        if (CompOffWalletEntry::query()->where('operation_key', $operationKey)->exists()) {
            return;
        }
        $totals = CompOffWalletEntry::query()->where('employee_id', $employee->id)
            ->selectRaw('COALESCE(SUM(earned_delta), 0) AS earned, COALESCE(SUM(reserved_delta), 0) AS reserved, COALESCE(SUM(used_delta), 0) AS used')->first();
        $nextEarned = (int) $totals->earned + $earned;
        $nextReserved = (int) $totals->reserved + $reserved;
        $nextUsed = (int) $totals->used + $used;
        if ($nextEarned < 0 || $nextReserved < 0 || $nextUsed < 0 || $nextEarned - $nextReserved - $nextUsed < 0) {
            throw ValidationException::withMessages(['credits' => 'Not enough available Comp-Off credits.']);
        }
        CompOffWalletEntry::query()->create([
            'reference' => $reference, 'employee_id' => $employee->id,
            'comp_off_sunday_duty_id' => $duty?->id, 'comp_off_credit_request_id' => $request?->id,
            'kind' => $kind, 'earned_delta' => $earned, 'reserved_delta' => $reserved, 'used_delta' => $used,
            'actor_user_id' => $actor->id, 'operation_key' => $operationKey,
            'reason' => $reason, 'occurred_at' => now(), 'created_at' => now(),
        ]);
    }

    private function event(?CompOffSundayDuty $duty, ?CompOffCreditRequest $request, string $action, User $actor, ?string $reason = null): void
    {
        CompOffApprovalEvent::query()->create([
            'comp_off_sunday_duty_id' => $duty?->id, 'comp_off_credit_request_id' => $request?->id,
            'action' => $action, 'actor_user_id' => $actor->id,
            'reason' => $reason, 'occurred_at' => now(), 'created_at' => now(),
        ]);
    }

    private function reference(string $key, string $prefix): string
    {
        $year = now('Asia/Karachi')->year;

        return $prefix.'-'.$year.'-'.str_pad((string) $this->references->next('comp_off_'.$key.':'.$year), 6, '0', STR_PAD_LEFT);
    }
}
