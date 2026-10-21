<?php

namespace Tests\Feature\Hr;

use App\Enums\EmployeeRole;
use App\Models\Employee;
use App\Models\HistoricalLeaveRecord;
use App\Models\LeaveCycleAdjustment;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Hr\JoiningDateLeaveBalanceService;
use App\Services\Hr\LeaveBalanceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class JoiningDateLeaveBalanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_anniversary_balance_separates_cycles_current_and_historical_leave_pending_and_adjustments(): void
    {
        [$user, $employee] = $this->verifiedEmployee('2025-03-15');
        $annual = LeaveType::query()->where('code', 'annual')->firstOrFail();
        $unpaid = LeaveType::query()->where('code', 'unpaid')->firstOrFail();
        $policy = LeavePolicy::query()->whereNull('effective_to')->latest('effective_from')->firstOrFail();

        $this->request($employee, $annual, $policy, 'approved', '2026-03-16', '2.00');
        $this->request($employee, $annual, $policy, 'pending', '2026-03-18', '1.00');
        $historical = HistoricalLeaveRecord::query()->create([
            'reference' => 'HLR-2026-TEST0001', 'record_group_uuid' => fake()->uuid(), 'employee_id' => $employee->id,
            'leave_type_id' => $annual->id, 'leave_policy_id' => $policy->id, 'from_date' => '2026-03-20', 'to_date' => '2026-03-20',
            'leave_period_start' => '2026-03-15', 'leave_period_end' => '2027-03-14', 'chargeable_days' => '1.00',
            'calculation_method' => 'manual_verified', 'manual_override_reason' => 'Verified paper record', 'approval_status' => 'approved',
            'reason' => 'Imported source register', 'source' => 'manual', 'recorded_by_user_id' => $user->id, 'recorded_at' => now(),
            'approved_by_user_id' => $user->id, 'approved_at' => now(), 'idempotency_key' => fake()->uuid(),
        ]);
        $this->assertTrue($historical->type->consumes_annual_entitlement);
        $this->assertSame('manual_verified', $historical->calculation_method);
        $this->assertSame(1, HistoricalLeaveRecord::query()->where('employee_id', $employee->id)->count());
        $this->assertSame(1, HistoricalLeaveRecord::query()->where('employee_id', $employee->id)
            ->where('calculation_method', 'manual_verified')->whereDate('leave_period_start', '2026-03-15')->count());
        $this->assertSame(1, HistoricalLeaveRecord::query()->where('employee_id', $employee->id)
            ->whereHas('type', fn ($types) => $types->where('consumes_annual_entitlement', true))->count());
        $this->request($employee, $unpaid, $policy, 'approved', '2026-03-23', '1.00');
        LeaveCycleAdjustment::query()->create([
            'reference' => 'LCA-2026-TEST01', 'employee_id' => $employee->id,
            'leave_period_start' => '2026-03-15', 'leave_period_end' => '2027-03-14',
            'adjustment_type' => 'credit', 'days' => '2.00', 'reason' => 'Verified opening balance adjustment',
            'supporting_reference' => 'Owner-reviewed opening balance record',
            'recorded_by_user_id' => $user->id, 'recorded_at' => now(), 'idempotency_key' => fake()->uuid(),
        ]);

        $balance = app(JoiningDateLeaveBalanceService::class)->forDate($employee, '2026-03-24');

        $this->assertSame('2026-03-15', $balance->periodStart);
        $this->assertSame('2027-03-14', $balance->periodEnd);
        $this->assertSame('12.00', $balance->entitlement);
        $this->assertSame('2.00', $balance->adjustments);
        $this->assertSame('1.00', $balance->historicalUsed);
        $this->assertSame('2.00', $balance->currentUsed);
        $this->assertSame('3.00', $balance->used);
        $this->assertSame('1.00', $balance->pending);
        $this->assertSame('11.00', $balance->remaining);
        $this->assertSame('10.00', $balance->availableAfterPending);
        $this->assertSame('1.00', $balance->unpaidUsed);
        $this->assertSame('0.00', $balance->carryForward);

        $previous = app(JoiningDateLeaveBalanceService::class)->forDate($employee, '2026-03-14');
        $this->assertSame('2025-03-15', $previous->periodStart);
        $this->assertSame('0.00', $previous->used);
        $this->assertSame('0.00', $previous->carryForward);
    }

    public function test_unverified_joining_date_cannot_produce_a_leave_balance(): void
    {
        $user = User::factory()->create();
        $employee = Employee::factory()->for($user)->create(['joining_date' => '2025-01-01']);

        $this->expectException(ValidationException::class);
        app(JoiningDateLeaveBalanceService::class)->forDate($employee, '2026-01-01');
    }

    public function test_existing_leave_balance_service_exposes_anniversary_cycles_to_callers(): void
    {
        [, $employee] = $this->verifiedEmployee('2025-03-15');
        $annual = LeaveType::query()->where('code', 'annual')->firstOrFail();
        $policy = LeavePolicy::query()->whereNull('effective_to')->latest('effective_from')->firstOrFail();
        $this->request($employee, $annual, $policy, 'approved', '2026-03-13', '1.00');
        $this->request($employee, $annual, $policy, 'pending', '2026-03-16', '1.00');

        $balances = app(LeaveBalanceService::class);
        $previous = $balances->forDate($employee, '2026-03-13');
        $current = $balances->forDate($employee, '2026-03-16');

        $this->assertSame('2025-03-15', $previous->periodStart);
        $this->assertSame('2026-03-14', $previous->periodEnd);
        $this->assertSame('1.00', $previous->used);
        $this->assertSame('0.00', $previous->pending);
        $this->assertSame('2026-03-15', $current->periodStart);
        $this->assertSame('2027-03-14', $current->periodEnd);
        $this->assertSame('0.00', $current->used);
        $this->assertSame('1.00', $current->pending);
        $this->assertSame('12.00', $current->entitlement);
        $this->assertSame('0.00', $current->carryForward);
    }

    public function test_february_29_employee_receives_one_12_day_entitlement_per_observed_anniversary_cycle(): void
    {
        [, $employee] = $this->verifiedEmployee('2024-02-29');
        $balances = app(JoiningDateLeaveBalanceService::class);

        $beforeNonLeapAnniversary = $balances->forDate($employee, '2025-02-27');
        $atNonLeapAnniversary = $balances->forDate($employee, '2025-02-28');
        $beforeLeapAnniversary = $balances->forDate($employee, '2028-02-28');
        $atLeapAnniversary = $balances->forDate($employee, '2028-02-29');

        $this->assertSame('2024-02-29', $beforeNonLeapAnniversary->periodStart);
        $this->assertSame('2025-02-27', $beforeNonLeapAnniversary->periodEnd);
        $this->assertSame('2025-02-28', $atNonLeapAnniversary->periodStart);
        $this->assertSame('2026-02-27', $atNonLeapAnniversary->periodEnd);
        $this->assertSame('2027-02-28', $beforeLeapAnniversary->periodStart);
        $this->assertSame('2028-02-28', $beforeLeapAnniversary->periodEnd);
        $this->assertSame('2028-02-29', $atLeapAnniversary->periodStart);
        $this->assertSame('2029-02-27', $atLeapAnniversary->periodEnd);

        foreach ([$beforeNonLeapAnniversary, $atNonLeapAnniversary, $beforeLeapAnniversary, $atLeapAnniversary] as $balance) {
            $this->assertSame('12.00', $balance->entitlement);
            $this->assertSame('0.00', $balance->carryForward);
        }
        $this->assertSame('2025-02-28', CarbonImmutable::parse($beforeNonLeapAnniversary->periodEnd)->addDay()->toDateString());
        $this->assertSame('2028-02-29', CarbonImmutable::parse($beforeLeapAnniversary->periodEnd)->addDay()->toDateString());
    }

    /** @return array{User,Employee} */
    private function verifiedEmployee(string $joiningDate): array
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        $employee = Employee::factory()->for($user)->role(EmployeeRole::Staff)->create([
            'email' => $user->email, 'joining_date' => $joiningDate, 'status' => true,
            'joining_date_verified_at' => now(), 'joining_date_verified_by_user_id' => $user->id,
        ]);

        return [$user, $employee];
    }

    private function request(Employee $employee, LeaveType $type, LeavePolicy $policy, string $status, string $date, string $units): LeaveRequest
    {
        $days = (int) $units;
        $endDate = CarbonImmutable::parse($date)->addDays(max(0, $days - 1))->toDateString();
        $request = LeaveRequest::query()->create([
            'reference' => 'LVR-'.substr(hash('sha256', $date.$status.$employee->id), 0, 15),
            'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'leave_policy_id' => $policy->id,
            'from_date' => $date, 'to_date' => $endDate, 'requested_working_days' => $units,
            'status' => $status, 'reason' => 'Test leave history', 'submitted_at' => now(),
            'decided_by_user_id' => $status === 'approved' ? $employee->user_id : null,
            'decided_at' => $status === 'approved' ? now() : null, 'idempotency_key' => fake()->uuid(),
        ]);
        for ($offset = 0; $offset < $days; $offset++) {
            LeaveRequestDay::query()->create([
                'leave_request_id' => $request->id, 'leave_date' => CarbonImmutable::parse($date)->addDays($offset)->toDateString(),
                'classification' => 'working_leave', 'counts_as_leave' => true, 'leave_units' => '1.00',
            ]);
        }

        return $request;
    }
}
