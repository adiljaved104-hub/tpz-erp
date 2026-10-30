<?php

namespace Tests\Feature\Hr;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\AttendanceStatus;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Filament\Pages\Hr\LeaveManagement;
use App\Models\AttendanceCorrection;
use App\Models\CompensatoryOff;
use App\Models\CompOffApprovalEvent;
use App\Models\CompOffCreditRequest;
use App\Models\CompOffWalletEntry;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeePermissionOverride;
use App\Models\LeavePolicy;
use App\Models\PublicHoliday;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleAssignment;
use App\Models\WorkScheduleDay;
use App\Services\Hr\BiometricAttendanceProcessor;
use App\Services\Hr\CompOffCreditService;
use App\Services\Hr\EffectiveWorkScheduleResolver;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class CompOffCreditWalletTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_prospective_standard_schedule_and_employee_override(): void
    {
        [$owner, $staff] = $this->people();
        $resolver = app(EffectiveWorkScheduleResolver::class);
        $monday = $resolver->day($staff->employee, CarbonImmutable::parse('2026-11-02'));
        $saturday = $resolver->day($staff->employee, CarbonImmutable::parse('2026-11-07'));
        $sunday = $resolver->day($staff->employee, CarbonImmutable::parse('2026-11-08'));
        $this->assertTrue($monday['day']->is_working_day);
        $this->assertSame('17:00', substr($monday['day']->expected_end_time, 0, 5));
        $this->assertTrue($saturday['day']->is_working_day);
        $this->assertSame('16:00', substr($saturday['day']->expected_end_time, 0, 5));
        $this->assertFalse($sunday['day']->is_working_day);
        $this->assertNull($resolver->assignment($staff->employee, CarbonImmutable::parse('2026-10-20')));

        $override = WorkSchedule::query()->create([
            'code' => 'TEST_SUN_WORK', 'name' => 'Sunday working test', 'timezone' => 'Asia/Karachi',
            'schedule_type' => 'custom', 'cycle_length_weeks' => 1, 'status' => true,
        ]);
        foreach (range(0, 6) as $weekday) {
            WorkScheduleDay::query()->create([
                'work_schedule_id' => $override->id, 'cycle_week' => 1, 'weekday' => $weekday,
                'is_working_day' => $weekday === 0, 'expected_start_time' => $weekday === 0 ? '11:00' : null,
                'expected_end_time' => $weekday === 0 ? '17:00' : null,
                'earns_compensatory_off' => false,
            ]);
        }
        WorkScheduleAssignment::query()->create([
            'work_schedule_id' => $override->id, 'employee_id' => $staff->employee->id,
            'effective_from' => '2026-11-01', 'assigned_by_user_id' => $owner->id,
            'reason' => 'Approved individual pattern',
        ]);
        $this->assertSame($override->id, $resolver->day($staff->employee, CarbonImmutable::parse('2026-11-08'))['assignment']->work_schedule_id);
        $this->assertFalse($resolver->day($staff->employee, CarbonImmutable::parse('2026-11-07'))['day']->is_working_day);
    }

    public function test_leave_page_recognizes_the_effective_company_schedule(): void
    {
        [, $staff] = $this->people();
        Carbon::setTestNow('2026-11-02 10:00:00 Asia/Karachi');

        Livewire::actingAs($staff)->test(LeaveManagement::class)
            ->assertViewHas('scheduleConfigured', true);
    }

    public function test_verified_sunday_earns_once_and_saturday_sunday_request_uses_one_credit(): void
    {
        [$owner, $staff] = $this->people();
        $service = app(CompOffCreditService::class);
        Carbon::setTestNow('2026-11-01 09:00:00 Asia/Karachi');
        $duty = $service->authorizeSunday([
            'employee_id' => $staff->employee->id, 'duty_date' => '2026-11-08',
            'reason' => 'Approved test Sunday duty', 'idempotency_key' => (string) Str::uuid(),
        ], $owner);
        $this->assertSame('authorized', $duty->status);
        $this->pairedAttendance($staff->employee, '2026-11-08', 360);
        Carbon::setTestNow('2026-11-09 09:00:00 Asia/Karachi');
        $service->decideSunday($duty, true, $owner);
        $service->decideSunday($duty, true, $owner);
        $this->assertSame(1, $service->balance($staff->employee, $staff)['available']);
        $this->assertSame(1, CompOffWalletEntry::query()->where('employee_id', $staff->employee->id)->where('kind', 'earned')->count());
        $request = $service->submit([
            'from_date' => '2026-11-14', 'to_date' => '2026-11-15',
            'reason' => 'Use one earned Sunday credit', 'idempotency_key' => (string) Str::uuid(),
        ], $staff);
        $this->assertSame(1, $request->credits_required);
        $this->assertSame(['working_day', 'weekly_off'], $request->days()->orderBy('off_date')->pluck('classification')->all());
        $this->assertSame(0, $service->balance($staff->employee, $staff)['available']);
        $this->assertSame(1, $service->balance($staff->employee, $staff)['reserved']);
        $service->decideRequest($request, true, $owner);
        $service->decideRequest($request, true, $owner);
        $this->assertSame(1, $service->balance($staff->employee, $staff)['used']);
        $this->assertSame(0, $service->balance($staff->employee, $staff)['reserved']);
        $this->assertSame(AttendanceStatus::CompensatoryOff,
            EmployeeAttendance::query()->where('employee_id', $staff->employee->id)
                ->whereDate('attendance_date', '2026-11-14')->firstOrFail()->status);
        $this->assertFalse(EmployeeAttendance::query()->where('employee_id', $staff->employee->id)
            ->whereDate('attendance_date', '2026-11-15')->where('status', AttendanceStatus::CompensatoryOff)->exists());
    }

    public function test_missing_checkout_and_insufficient_minutes_never_earn_credit(): void
    {
        [$owner, $staff] = $this->people();
        $service = app(CompOffCreditService::class);
        Carbon::setTestNow('2026-11-01 09:00:00 Asia/Karachi');
        $duty = $service->authorizeSunday([
            'employee_id' => $staff->employee->id, 'duty_date' => '2026-11-08',
            'reason' => 'Approved test Sunday duty', 'idempotency_key' => (string) Str::uuid(),
        ], $owner);
        $attendance = $this->pairedAttendance($staff->employee, '2026-11-08', 359);
        Carbon::setTestNow('2026-11-09 09:00:00 Asia/Karachi');
        try {
            $service->decideSunday($duty, true, $owner);
            $this->fail('Insufficient verified minutes should fail.');
        } catch (ValidationException) {
            $this->assertSame(0, CompOffWalletEntry::query()->count());
        }
        $attendance->forceFill(['worked_minutes' => 360, 'last_check_out_at' => null])->save();
        $this->expectException(ValidationException::class);
        $service->decideSunday($duty, true, $owner);
    }

    public function test_sunday_duty_rejection_is_audited_without_awarding_credit(): void
    {
        [$owner, $staff] = $this->people();
        $service = app(CompOffCreditService::class);
        Carbon::setTestNow('2026-11-01 09:00:00 Asia/Karachi');
        $duty = $service->authorizeSunday([
            'employee_id' => $staff->employee->id, 'duty_date' => '2026-11-08',
            'reason' => 'Proposed Sunday shift', 'idempotency_key' => (string) Str::uuid(),
        ], $owner);
        $service->decideSunday($duty, false, $owner, 'Sunday assignment was cancelled');
        $service->decideSunday($duty, false, $owner, 'Sunday assignment was cancelled');
        $this->assertSame('rejected', $duty->fresh()->status);
        $this->assertSame(0, $service->balance($staff->employee, $staff)['earned']);
        $this->assertSame(2, CompOffApprovalEvent::query()->where('comp_off_sunday_duty_id', $duty->id)->count());
    }

    public function test_rejection_releases_reservation_and_out_of_scope_manager_is_denied(): void
    {
        [$owner, $staff] = $this->people();
        $otherTeam = Team::query()->create(['name' => 'Other', 'status' => true]);
        $otherManager = $this->user(EmployeeRole::Manager, $otherTeam);
        $service = app(CompOffCreditService::class);
        $this->seedCredit($staff->employee, $owner);
        Carbon::setTestNow('2026-11-09 09:00:00 Asia/Karachi');
        $request = $service->submit([
            'from_date' => '2026-11-14', 'to_date' => '2026-11-15',
            'reason' => 'Use earned duty credit', 'idempotency_key' => (string) Str::uuid(),
        ], $staff);
        $this->assertSame(0, $service->balance($staff->employee, $staff)['available']);
        try {
            $service->decideRequest($request, true, $otherManager);
            $this->fail('Out-of-scope manager should be denied.');
        } catch (AuthorizationException) {
            $this->assertSame('pending', $request->fresh()->status);
        }
        $service->decideRequest($request, false, $owner, 'Employee no longer needs this absence');
        $this->assertSame(1, $service->balance($staff->employee, $staff)['available']);
        $this->assertSame(0, $service->balance($staff->employee, $staff)['reserved']);
    }

    public function test_pending_cancellation_releases_credit_and_duplicate_submission_is_idempotent(): void
    {
        [$owner, $staff] = $this->people();
        $service = app(CompOffCreditService::class);
        $this->seedCredit($staff->employee, $owner);
        Carbon::setTestNow('2026-11-09 09:00:00 Asia/Karachi');
        $key = (string) Str::uuid();
        $data = ['from_date' => '2026-11-14', 'to_date' => '2026-11-15',
            'reason' => 'Use earned Sunday credit', 'idempotency_key' => $key];
        $first = $service->submit($data, $staff);
        $second = $service->submit($data, $staff);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CompOffCreditRequest::query()->count());
        $this->assertSame(1, $service->balance($staff->employee, $staff)['reserved']);
        $service->cancel($first, $staff, 'Plans changed before the absence');
        $service->cancel($first, $staff, 'Plans changed before the absence');
        $this->assertSame(1, $service->balance($staff->employee, $staff)['available']);
        $this->assertSame(0, $service->balance($staff->employee, $staff)['reserved']);
        $this->assertSame(2, CompOffWalletEntry::query()->where('comp_off_credit_request_id', $first->id)->count());
    }

    public function test_comp_off_approval_survives_biometric_reprocessing_and_does_not_change_annual_policy(): void
    {
        [$owner, $staff] = $this->people();
        $service = app(CompOffCreditService::class);
        $this->seedCredit($staff->employee, $owner);
        $policy = LeavePolicy::query()->latest('effective_from')->firstOrFail();
        $before = $policy->toArray();
        Carbon::setTestNow('2026-11-09 09:00:00 Asia/Karachi');
        $request = $service->submit([
            'from_date' => '2026-11-14', 'to_date' => '2026-11-14',
            'reason' => 'Use one credit independently', 'idempotency_key' => (string) Str::uuid(),
        ], $staff);
        $service->decideRequest($request, true, $owner);
        $reprocessed = app(BiometricAttendanceProcessor::class)->rebuild($staff->employee, CarbonImmutable::parse('2026-11-14'));
        $this->assertSame(AttendanceStatus::CompensatoryOff, $reprocessed->status);
        $this->assertSame($before, $policy->fresh()->toArray());
        $this->assertSame(1, $service->balance($staff->employee, $staff)['used']);
        $this->assertSame(0, $service->balance($staff->employee, $staff)['available']);
    }

    public function test_two_requests_cannot_reserve_the_same_credit_and_employee_cannot_self_approve(): void
    {
        [$owner, $staff] = $this->people();
        $service = app(CompOffCreditService::class);
        $this->seedCredit($staff->employee, $owner);
        Carbon::setTestNow('2026-11-09 09:00:00 Asia/Karachi');
        $first = $service->submit([
            'from_date' => '2026-11-14', 'to_date' => '2026-11-14',
            'reason' => 'First credit request', 'idempotency_key' => (string) Str::uuid(),
        ], $staff);
        try {
            $service->decideRequest($first, true, $staff);
            $this->fail('Employees must not approve their own requests.');
        } catch (AuthorizationException) {
            $this->assertSame('pending', $first->fresh()->status);
        }
        $this->expectException(ValidationException::class);
        $service->submit([
            'from_date' => '2026-11-16', 'to_date' => '2026-11-16',
            'reason' => 'Second credit request', 'idempotency_key' => (string) Str::uuid(),
        ], $staff);
    }

    public function test_manager_can_manage_only_scoped_employees_and_a_permission_denial_is_enforced(): void
    {
        [$owner, $staff] = $this->people();
        $manager = $this->user(EmployeeRole::Manager, $staff->employee->team);
        $service = app(CompOffCreditService::class);
        $this->assertTrue($service->canManage($manager, $staff->employee));
        $this->assertFalse($service->canManage($manager, $manager->employee));
        EmployeePermissionOverride::query()->create([
            'employee_id' => $manager->employee->id, 'permission_key' => HrPermission::CompOffManage->value,
            'effect' => EmployeePermissionEffect::Deny, 'granted_by_user_id' => $owner->id,
            'reason' => 'Scoped permission-denial regression',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($manager->employee->id);
        $this->assertFalse($service->canManage($manager, $staff->employee));
    }

    public function test_credits_accumulate_across_months_and_two_working_days_use_two_credits(): void
    {
        [$owner, $staff] = $this->people();
        $service = app(CompOffCreditService::class);
        Carbon::setTestNow('2026-11-01 09:00:00 Asia/Karachi');
        $first = $service->authorizeSunday([
            'employee_id' => $staff->employee->id, 'duty_date' => '2026-11-08',
            'reason' => 'Approved November Sunday duty', 'idempotency_key' => (string) Str::uuid(),
        ], $owner);
        $this->pairedAttendance($staff->employee, '2026-11-08', 360);
        Carbon::setTestNow('2026-11-09 09:00:00 Asia/Karachi');
        $service->decideSunday($first, true, $owner);
        $second = $service->authorizeSunday([
            'employee_id' => $staff->employee->id, 'duty_date' => '2026-12-06',
            'reason' => 'Approved December Sunday duty', 'idempotency_key' => (string) Str::uuid(),
        ], $owner);
        $this->pairedAttendance($staff->employee, '2026-12-06', 360);
        Carbon::setTestNow('2026-12-07 09:00:00 Asia/Karachi');
        $service->decideSunday($second, true, $owner);
        $this->assertSame(2, $service->balance($staff->employee, $staff)['available']);
        $request = $service->submit([
            'from_date' => '2026-12-12', 'to_date' => '2026-12-14',
            'reason' => 'Use two credits over the weekend', 'idempotency_key' => (string) Str::uuid(),
        ], $staff);
        $this->assertSame(2, $request->credits_required);
        $this->assertSame(['working_day', 'weekly_off', 'working_day'],
            $request->days()->orderBy('off_date')->pluck('classification')->all());
        $service->decideRequest($request, true, $owner);
        $this->assertSame(2, $service->balance($staff->employee, $staff)['used']);
        $this->assertSame(0, $service->balance($staff->employee, $staff)['available']);
    }

    public function test_approved_future_absence_requires_manager_reversal_and_rebuilds_attendance(): void
    {
        [$owner, $staff] = $this->people();
        $service = app(CompOffCreditService::class);
        $this->seedCredit($staff->employee, $owner);
        Carbon::setTestNow('2026-11-09 09:00:00 Asia/Karachi');
        $request = $service->submit([
            'from_date' => '2026-11-14', 'to_date' => '2026-11-14',
            'reason' => 'Use one credit on Saturday', 'idempotency_key' => (string) Str::uuid(),
        ], $staff);
        $service->decideRequest($request, true, $owner);
        try {
            $service->cancel($request, $staff, 'Employee asks to reverse approved day');
            $this->fail('Only a manager may reverse approved absence.');
        } catch (AuthorizationException) {
            $this->assertSame('approved', $request->fresh()->status);
        }
        $service->cancel($request, $owner, 'Approved absence cancelled before its date');
        $this->assertSame(1, $service->balance($staff->employee, $staff)['available']);
        $this->assertSame(0, $service->balance($staff->employee, $staff)['used']);
        $attendance = EmployeeAttendance::query()->where('employee_id', $staff->employee->id)
            ->whereDate('attendance_date', '2026-11-14')->firstOrFail();
        $this->assertSame(AttendanceStatus::Absent, $attendance->status);
        $this->assertSame(1, $attendance->corrections()->count());
    }

    public function test_public_holiday_in_range_does_not_consume_a_credit(): void
    {
        [$owner, $staff] = $this->people();
        $service = app(CompOffCreditService::class);
        $this->seedCredit($staff->employee, $owner);
        PublicHoliday::query()->create([
            'name' => 'Test public holiday', 'holiday_date' => '2026-11-16',
            'status' => true, 'created_by_user_id' => $owner->id,
        ]);
        Carbon::setTestNow('2026-11-09 09:00:00 Asia/Karachi');
        $request = $service->submit([
            'from_date' => '2026-11-14', 'to_date' => '2026-11-16',
            'reason' => 'Saturday followed by off and holiday', 'idempotency_key' => (string) Str::uuid(),
        ], $staff);
        $this->assertSame(1, $request->credits_required);
        $this->assertSame(['working_day', 'weekly_off', 'public_holiday'],
            $request->days()->orderBy('off_date')->pluck('classification')->all());
        $this->assertSame(1, $service->balance($staff->employee, $staff)['reserved']);
    }

    public function test_older_comp_off_history_is_preserved_and_not_converted_to_wallet_credit(): void
    {
        [$owner, $staff] = $this->people();
        $legacy = CompensatoryOff::query()->create([
            'reference' => 'COF-2026-999998', 'employee_id' => $staff->employee->id,
            'earned_work_date' => '2026-10-25', 'off_date' => '2026-11-03',
            'source' => 'scheduled_sunday', 'status' => 'approved',
            'granted_by_user_id' => $owner->id, 'approved_by_user_id' => $owner->id,
            'approved_at' => now(), 'reason' => 'Original approved historical record',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $wallet = app(CompOffCreditService::class)->balance($staff->employee, $staff);
        $this->assertSame(0, $wallet['available']);
        $this->assertSame(1, $wallet['legacy_unreconciled']);
        $this->assertSame('approved', $legacy->fresh()->status);
        $this->assertDatabaseCount('comp_off_wallet_entries', 0);
    }

    private function people(): array
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);

        return [$this->user(EmployeeRole::Owner, $team), $this->user(EmployeeRole::Staff, $team)];
    }

    private function user(EmployeeRole $role, Team $team): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create([
            'email' => $user->email, 'team_id' => $team->id, 'status' => true,
        ]);

        return $user->refresh();
    }

    private function pairedAttendance(Employee $employee, string $date, int $minutes): EmployeeAttendance
    {
        $start = CarbonImmutable::parse($date.' 11:00:00', 'Asia/Karachi');
        $attendance = EmployeeAttendance::query()->create([
            'employee_id' => $employee->id, 'attendance_date' => $date,
            'work_schedule_id' => WorkSchedule::query()->where('code', 'PK_STD_2026_11')->value('id'),
            'first_check_in_at' => $start->utc(), 'last_check_out_at' => $start->addMinutes($minutes)->utc(),
            'worked_minutes' => $minutes, 'status' => AttendanceStatus::WeekendOff,
            'source' => 'manual', 'is_overridden' => true, 'calculated_at' => now(),
        ]);
        AttendanceCorrection::query()->create([
            'employee_attendance_id' => $attendance->id, 'actor_user_id' => User::query()->firstOrFail()->id,
            'reason' => 'Verified authorized paired Sunday attendance', 'old_values' => [],
            'new_values' => ['first_check_in_at' => $start->utc()->toIso8601String(),
                'last_check_out_at' => $start->addMinutes($minutes)->utc()->toIso8601String()],
            'idempotency_key' => (string) Str::uuid(), 'occurred_at' => now(), 'created_at' => now(),
        ]);

        return $attendance;
    }

    private function seedCredit(Employee $employee, User $actor): void
    {
        CompOffWalletEntry::query()->create([
            'reference' => 'CWL-2026-999999', 'employee_id' => $employee->id, 'kind' => 'earned',
            'earned_delta' => 1, 'reserved_delta' => 0, 'used_delta' => 0,
            'actor_user_id' => $actor->id, 'operation_key' => 'test:earned:'.$employee->id,
            'reason' => 'Test fixture', 'occurred_at' => now(), 'created_at' => now(),
        ]);
    }
}
