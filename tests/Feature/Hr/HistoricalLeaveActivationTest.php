<?php

namespace Tests\Feature\Hr;

use App\Enums\EmployeeRole;
use App\Models\AttendancePolicy;
use App\Models\Employee;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleAssignment;
use App\Models\WorkScheduleDay;
use App\Services\Hr\HistoricalLeaveActivationService;
use App\Services\Hr\HistoricalLeaveService;
use App\Services\Hr\JoiningDateCorrectionService;
use App\Services\Hr\LeaveService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HistoricalLeaveActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_individual_activation_leaves_other_employees_and_shared_policy_unchanged(): void
    {
        [$owner, $staff] = $this->people();
        $this->configureSchedule($staff->employee, $owner);
        $service = app(HistoricalLeaveActivationService::class);
        $policyBefore = LeavePolicy::query()->orderBy('id')->get()->toArray();

        $service->review($staff->employee, $owner);
        $this->assertSame('Ready for Activation', $service->employeeStatus($staff->employee));
        $this->assertSame('Pending HR Review', $service->employeeStatus($owner->employee));
        $service->activateEmployees([$staff->employee->id], $owner);

        $this->assertSame('Active', $service->employeeStatus($staff->employee));
        $this->assertFalse($service->isActiveFor($owner->employee));
        $this->assertFalse($service->isActive());
        $this->assertSame($policyBefore, LeavePolicy::query()->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('historical_leave_workflow_activations', 0);
        $this->assertDatabaseHas('employee_annual_leave_activations', [
            'employee_id' => $staff->employee->id, 'approved_by_user_id' => $owner->id,
        ]);
        try {
            $service->activate($owner);
            $this->fail('Company-wide activation was allowed after individual activation began.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Company-wide activation is unavailable', $exception->errors()['activation'][0]);
        }

        $request = app(LeaveService::class)->submit([
            'leave_type_id' => LeaveType::query()->where('code', 'annual')->value('id'),
            'from_date' => CarbonImmutable::today()->next(1)->toDateString(),
            'to_date' => CarbonImmutable::today()->next(1)->toDateString(),
            'reason' => 'Approved individual annual leave',
        ], $staff);
        $this->assertSame('pending', $request->status);
        $this->assertSame('approved', app(LeaveService::class)->approve($request, $owner)->status);
        try {
            $service->assertAnnualRequestsActive($owner->employee);
            $this->fail('Activating staff unlocked the Owner employee too.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('leave_type_id', $exception->errors());
        }
    }

    public function test_selected_employee_activation_is_atomic_and_requires_current_hr_review(): void
    {
        [$owner, $staff] = $this->people();
        $other = User::factory()->create();
        Employee::factory()->for($other)->role(EmployeeRole::Staff)->create([
            'email' => $other->email, 'joining_date' => '2025-02-01',
            'joining_date_verified_at' => now(), 'joining_date_verified_by_user_id' => $owner->id,
        ]);
        $service = app(HistoricalLeaveActivationService::class);
        $service->review($staff->employee, $owner);
        try {
            $service->activateEmployees([$staff->employee->id, $other->employee->id], $owner);
            $this->fail('Selected activation accepted an employee without HR review.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('activation', $exception->errors());
        }
        $this->assertDatabaseCount('employee_annual_leave_activations', 0);
        $service->review($other->employee, $owner);
        try {
            $service->activateEmployees([$staff->employee->id], $staff);
            $this->fail('A Staff user activated Annual Leave.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('employee_annual_leave_activations', 0);
        }
        $service->activateEmployees([$staff->employee->id, $other->employee->id], $owner);
        $this->assertDatabaseCount('employee_annual_leave_activations', 2);
        $this->assertFalse($service->isActiveFor($owner->employee));
    }

    public function test_new_ordinary_leave_request_invalidates_review_before_individual_activation(): void
    {
        [$owner, $staff] = $this->people();
        $service = app(HistoricalLeaveActivationService::class);
        $service->review($staff->employee, $owner);
        LeaveRequest::query()->create([
            'reference' => 'LVR-INDIVIDUAL-REVIEW-1', 'employee_id' => $staff->employee->id,
            'leave_type_id' => LeaveType::query()->where('code', 'annual')->value('id'),
            'leave_policy_id' => LeavePolicy::query()->where('status', true)->value('id'),
            'from_date' => '2026-10-26', 'to_date' => '2026-10-26',
            'requested_working_days' => '1.00', 'status' => 'pending',
            'reason' => 'Existing ERP request', 'submitted_at' => now(), 'idempotency_key' => (string) Str::uuid(),
        ]);
        $this->assertFalse($service->isReviewed($staff->employee));
        $this->expectException(ValidationException::class);
        $service->activateEmployees([$staff->employee->id], $owner);
    }

    public function test_joining_date_correction_requires_new_owner_activation_after_hr_rereview(): void
    {
        [$owner, $staff] = $this->people();
        $service = app(HistoricalLeaveActivationService::class);
        $service->review($staff->employee, $owner);
        $service->activateEmployees([$staff->employee->id], $owner);
        $this->assertTrue($service->isActiveFor($staff->employee));

        app(JoiningDateCorrectionService::class)->verifyOrCorrect($staff->employee, [
            'joining_date' => '2025-02-01', 'reason' => 'Corrected against the employment agreement',
            'confirmed' => true,
        ], $owner);
        $this->assertFalse($service->isActiveFor($staff->employee->fresh()));
        $service->review($staff->employee->fresh(), $owner);
        $this->assertSame('Ready for Activation', $service->employeeStatus($staff->employee->fresh()));

        $service->activateEmployees([$staff->employee->id], $owner);
        $this->assertSame('Active', $service->employeeStatus($staff->employee->fresh()));
        $this->assertDatabaseCount('employee_annual_leave_activations', 2);
    }

    public function test_owner_activation_requires_current_review_for_every_employee_including_zero_leave(): void
    {
        [$owner, $staff] = $this->people();
        $service = app(HistoricalLeaveActivationService::class);

        $this->assertFalse($service->isActive());
        $this->assertSame(2, $service->status()['pending']);
        $service->review($staff->employee, $owner);
        $this->assertTrue($service->isReviewed($staff->employee));
        try {
            $service->activate($owner);
            $this->fail('Activation was accepted without a review for the Owner employee.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('activation', $exception->errors());
        }
        $service->review($owner->employee, $owner);
        try {
            $service->activate($staff);
            $this->fail('Staff activated the workflow.');
        } catch (AuthorizationException) {
            $this->assertFalse($service->isActive());
        }

        $service->activate($owner);
        $this->assertTrue($service->isActive());
        $policy = LeavePolicy::query()->where('status', true)->whereNull('effective_to')->firstOrFail();
        $this->assertSame('joining_anniversary', $policy->leave_year_basis);
        $this->assertSame('12.00', (string) $policy->annual_leave_entitlement_days);
        $this->assertFalse($policy->monthly_accrual_enabled);
        $this->assertFalse($policy->carry_forward_enabled);
        $this->assertDatabaseHas('leave_policy_events', [
            'leave_policy_id' => $policy->id, 'actor_user_id' => $owner->id,
            'event_type' => 'initial_anniversary_policy',
        ]);
        $this->assertSame(0, $service->status()['pending']);
        $this->assertDatabaseHas('historical_leave_workflow_activations', [
            'workflow' => 'annual_leave', 'approved_by_user_id' => $owner->id,
            'reviewed_employee_count' => 2,
        ]);
        $this->assertSame(2, DB::table('historical_leave_reconciliation_reviews')->count());
    }

    public function test_verified_joining_date_does_not_unlock_annual_requests_and_correction_stales_hr_review(): void
    {
        [$owner, $staff] = $this->people();
        $this->configureSchedule($staff->employee, $owner);
        $request = [
            'leave_type_id' => LeaveType::query()->where('code', 'annual')->value('id'),
            'from_date' => CarbonImmutable::today()->next(1)->toDateString(),
            'to_date' => CarbonImmutable::today()->next(1)->toDateString(),
            'reason' => 'Annual leave request',
        ];
        try {
            app(LeaveService::class)->submit($request, $staff);
            $this->fail('A verified date alone unlocked Annual Leave.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('leave_type_id', $exception->errors());
        }

        $service = app(HistoricalLeaveActivationService::class);
        $service->review($owner->employee, $owner);
        $service->review($staff->employee, $owner);
        app(JoiningDateCorrectionService::class)->verifyOrCorrect($staff->employee, [
            'joining_date' => '2025-02-01', 'reason' => 'HR checked the original employment agreement',
            'confirmed' => true,
        ], $owner);
        $this->assertFalse($service->isReviewed($staff->employee->fresh()));
        $this->expectException(ValidationException::class);
        $service->activate($owner);
    }

    public function test_existing_pending_annual_approval_and_new_unpaid_leave_continue_before_activation(): void
    {
        [$owner, $staff] = $this->people();
        $this->configureSchedule($staff->employee, $owner);
        $staff->employee->forceFill(['joining_date_verified_at' => null])->save();
        $annual = LeaveType::query()->where('code', 'annual')->firstOrFail();
        $existing = LeaveRequest::query()->create([
            'reference' => 'LVR-LEGACY-PENDING-1', 'employee_id' => $staff->employee->id,
            'leave_type_id' => $annual->id, 'leave_policy_id' => LeavePolicy::query()->where('status', true)->value('id'),
            'from_date' => '2026-10-26', 'to_date' => '2026-10-26', 'requested_working_days' => '1.00',
            'status' => 'pending', 'reason' => 'Existing request before HR rollout', 'submitted_at' => now(),
            'idempotency_key' => (string) Str::uuid(),
        ]);
        LeaveRequestDay::query()->create([
            'leave_request_id' => $existing->id, 'leave_date' => '2026-10-26',
            'classification' => 'working_leave', 'counts_as_leave' => true, 'leave_units' => '1.00',
        ]);

        $approved = app(LeaveService::class)->approve($existing, $owner);
        $this->assertSame('approved', $approved->status);
        $this->assertSame('calendar_year', LeavePolicy::query()->whereNull('effective_to')->firstOrFail()->leave_year_basis);

        $unpaid = app(LeaveService::class)->submit([
            'leave_type_id' => LeaveType::query()->where('code', 'unpaid')->value('id'),
            'from_date' => '2026-10-27', 'to_date' => '2026-10-27',
            'reason' => 'Unpaid leave remains available during reconciliation',
        ], $staff);
        $this->assertSame('pending', $unpaid->status);
    }

    public function test_annual_request_succeeds_only_after_owner_activation(): void
    {
        [$owner, $staff] = $this->people();
        $this->configureSchedule($staff->employee, $owner);
        $service = app(HistoricalLeaveActivationService::class);
        $service->review($owner->employee, $owner);
        $service->review($staff->employee, $owner);
        $service->activate($owner);

        $date = CarbonImmutable::today()->next(1)->toDateString();
        $request = app(LeaveService::class)->submit([
            'leave_type_id' => LeaveType::query()->where('code', 'annual')->value('id'),
            'from_date' => $date, 'to_date' => $date, 'reason' => 'Annual leave after activation',
        ], $staff);
        $this->assertSame('pending', $request->status);
        $this->assertSame($staff->employee->id, $request->employee_id);
    }

    public function test_future_employee_requires_individual_review_without_company_reactivation(): void
    {
        [$owner, $staff] = $this->people();
        $service = app(HistoricalLeaveActivationService::class);
        $service->review($owner->employee, $owner);
        $service->review($staff->employee, $owner);
        $service->activate($owner);

        $newUser = User::factory()->create();
        $newEmployee = Employee::factory()->for($newUser)->role(EmployeeRole::Staff)->create([
            'email' => $newUser->email, 'status' => true, 'joining_date' => '2026-01-01',
            'joining_date_verified_at' => now(), 'joining_date_verified_by_user_id' => $owner->id,
        ]);
        $this->configureSchedule($newEmployee, $owner);
        $date = CarbonImmutable::today()->next(1)->toDateString();
        $data = [
            'leave_type_id' => LeaveType::query()->where('code', 'annual')->value('id'),
            'from_date' => $date, 'to_date' => $date, 'reason' => 'New employee annual leave',
        ];

        try {
            app(LeaveService::class)->submit($data, $newUser->refresh());
            $this->fail('Company activation bypassed the new employee HR review.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('leave_type_id', $exception->errors());
        }

        $service->review($newEmployee, $owner);
        $request = app(LeaveService::class)->submit($data, $newUser->refresh());
        $this->assertSame($newEmployee->id, $request->employee_id);
        $this->assertSame(1, DB::table('historical_leave_workflow_activations')->count());
    }

    public function test_normal_annual_requests_do_not_reopen_an_approved_hr_review(): void
    {
        [$owner, $staff] = $this->people();
        $this->configureSchedule($staff->employee, $owner);
        $service = app(HistoricalLeaveActivationService::class);
        $service->review($owner->employee, $owner);
        $service->review($staff->employee, $owner);
        $service->activate($owner);

        $firstDate = CarbonImmutable::today()->next(1);
        $leave = app(LeaveService::class);
        foreach ([$firstDate, $firstDate->addWeek()] as $date) {
            $request = $leave->submit([
                'leave_type_id' => LeaveType::query()->where('code', 'annual')->value('id'),
                'from_date' => $date->toDateString(), 'to_date' => $date->toDateString(),
                'reason' => 'Annual leave after HR review',
            ], $staff);
            $this->assertSame('pending', $request->status);
        }

        $this->assertTrue($service->isReviewed($staff->employee->fresh()));
        $this->assertSame(1, DB::table('historical_leave_workflow_activations')->count());
    }

    public function test_new_historical_entry_invalidates_a_prior_review_and_staff_cannot_approve_activation(): void
    {
        [$owner, $staff] = $this->people();
        $this->configureSchedule($staff->employee, $owner);
        $service = app(HistoricalLeaveActivationService::class);
        $service->review($owner->employee, $owner);
        $service->review($staff->employee, $owner);
        $this->assertTrue($service->isReviewed($staff->employee));

        app(HistoricalLeaveService::class)->record([
            'employee_id' => $staff->employee->id,
            'leave_type_id' => LeaveType::query()->where('code', 'annual')->value('id'),
            'from_date' => '2026-08-03', 'to_date' => '2026-08-03',
            'approval_status' => 'approved', 'reason' => 'Previously consumed leave',
            'historical_source' => 'Signed register', 'confirmed' => true,
            'idempotency_key' => (string) Str::uuid(),
        ], $owner);

        $this->assertFalse($service->isReviewed($staff->employee->fresh()));
        $this->assertSame(1, $service->status()['pending']);
        try {
            $service->activate($owner);
            $this->fail('A stale HR review permitted activation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('activation', $exception->errors());
        }
        $service->review($staff->employee->fresh(), $owner);
        $service->activate($owner);
        $this->assertTrue($service->isActive());
    }

    public function test_request_day_and_approval_changes_each_invalidate_pre_activation_review(): void
    {
        [$owner, $staff] = $this->people();
        $service = app(HistoricalLeaveActivationService::class);
        $service->review($owner->employee, $owner);
        $service->review($staff->employee, $owner);

        $request = LeaveRequest::query()->create([
            'reference' => 'LVR-HIST-REVIEW-1', 'employee_id' => $staff->employee->id,
            'leave_type_id' => LeaveType::query()->where('code', 'annual')->value('id'),
            'leave_policy_id' => LeavePolicy::query()->where('status', true)->value('id'),
            'from_date' => '2026-10-26', 'to_date' => '2026-10-26', 'requested_working_days' => '1.00',
            'status' => 'pending', 'reason' => 'Existing pending leave', 'submitted_at' => now(),
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $this->assertFalse($service->isReviewed($staff->employee));
        $service->review($staff->employee, $owner);

        LeaveRequestDay::query()->create([
            'leave_request_id' => $request->id, 'leave_date' => '2026-10-26',
            'classification' => 'working_leave', 'counts_as_leave' => true, 'leave_units' => '1.00',
        ]);
        $this->assertFalse($service->isReviewed($staff->employee));
        $service->review($staff->employee, $owner);

        $request->forceFill(['status' => 'approved', 'decided_by_user_id' => $owner->id, 'decided_at' => now()])->save();
        $this->assertFalse($service->isReviewed($staff->employee));
        try {
            $service->activate($owner);
            $this->fail('An approval change after HR review was accepted for activation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('activation', $exception->errors());
        }
    }

    public function test_policy_and_entitlement_adjustment_changes_invalidate_pre_activation_review(): void
    {
        [$owner, $staff] = $this->people();
        $service = app(HistoricalLeaveActivationService::class);
        $service->review($owner->employee, $owner);
        $service->review($staff->employee, $owner);

        DB::table('leave_entitlement_adjustments')->insert([
            'reference' => 'LEA-HIST-REVIEW-1', 'employee_id' => $staff->employee->id,
            'leave_year' => 2026, 'days' => '1.00', 'reason' => 'Reviewed entitlement correction',
            'approved_by_user_id' => $owner->id, 'idempotency_key' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertFalse($service->isReviewed($staff->employee));
        $service->review($staff->employee, $owner);

        DB::table('leave_cycle_adjustments')->insert([
            'reference' => 'LCA-HIST-REVIEW-1', 'employee_id' => $staff->employee->id,
            'leave_period_start' => '2026-01-01', 'leave_period_end' => '2026-12-31',
            'adjustment_type' => 'credit', 'days' => '1.00', 'reason' => 'Cycle correction',
            'supporting_reference' => 'HR review', 'recorded_by_user_id' => $owner->id,
            'recorded_at' => now(), 'idempotency_key' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertFalse($service->isReviewed($staff->employee));
        $service->review($staff->employee, $owner);

        LeavePolicy::query()->where('status', true)->whereNull('effective_to')->firstOrFail()
            ->forceFill(['annual_leave_entitlement_days' => '13.00'])->save();
        $this->assertFalse($service->isReviewed($staff->employee));
        $this->assertFalse($service->isReviewed($owner->employee));
        $this->assertDatabaseCount('historical_leave_workflow_activations', 0);
    }

    /** @return array{User,User} */
    private function people(): array
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create([
            'email' => $owner->email, 'status' => true, 'joining_date' => '2020-01-01',
            'joining_date_verified_at' => now(), 'joining_date_verified_by_user_id' => $owner->id,
        ]);
        $staff = User::factory()->create();
        Employee::factory()->for($staff)->role(EmployeeRole::Staff)->create([
            'email' => $staff->email, 'status' => true, 'joining_date' => '2025-01-01',
            'joining_date_verified_at' => now(), 'joining_date_verified_by_user_id' => $owner->id,
        ]);

        return [$owner->refresh(), $staff->refresh()];
    }

    private function configureSchedule(Employee $employee, User $actor): void
    {
        $schedule = WorkSchedule::query()->create([
            'code' => 'LEAVE_ACTIVATION_TEST', 'name' => 'Leave activation test schedule',
            'timezone' => 'Asia/Karachi', 'schedule_type' => 'standard', 'cycle_length_weeks' => 1,
            'attendance_policy_id' => AttendancePolicy::query()->value('id'), 'status' => true,
        ]);
        foreach (range(0, 6) as $weekday) {
            $working = ! in_array($weekday, [0, 6], true);
            WorkScheduleDay::query()->create([
                'work_schedule_id' => $schedule->id, 'cycle_week' => 1, 'weekday' => $weekday,
                'is_working_day' => $working,
                'expected_start_time' => $working ? '09:00' : null,
                'expected_end_time' => $working ? '17:00' : null,
                'earns_compensatory_off' => false,
            ]);
        }
        WorkScheduleAssignment::query()->create([
            'work_schedule_id' => $schedule->id, 'employee_id' => $employee->id,
            'effective_from' => '2025-01-01', 'assigned_by_user_id' => $actor->id,
            'reason' => 'Test-only schedule',
        ]);
    }
}
