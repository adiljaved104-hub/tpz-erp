<?php

namespace Tests\Unit\Hr;

use App\Models\Employee;
use App\Services\Leave\AnnualLeaveCycleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AnnualLeaveCycleResolverTest extends TestCase
{
    public function test_resolves_joining_anniversary_period_and_boundary(): void
    {
        $employee = $this->employee('2025-03-15');
        $resolver = new AnnualLeaveCycleResolver;

        $first = $resolver->forDate($employee, '2026-03-14');
        $second = $resolver->forDate($employee, '2026-03-15');

        $this->assertSame('2025-03-15', $first->start->toDateString());
        $this->assertSame('2026-03-14', $first->end->toDateString());
        $this->assertSame(0, $first->completedYears);
        $this->assertSame('2026-03-15', $second->start->toDateString());
        $this->assertSame('2027-03-14', $second->end->toDateString());
        $this->assertSame(1, $second->completedYears);
    }

    public function test_unverified_joining_date_cannot_generate_a_balance_period(): void
    {
        $employee = $this->employee('2025-08-10', false);

        $this->expectException(ValidationException::class);
        (new AnnualLeaveCycleResolver)->forDate($employee, '2026-08-10');
    }

    public function test_leave_before_verified_joining_date_is_rejected(): void
    {
        $employee = $this->employee('2025-08-10');

        $this->expectException(ValidationException::class);
        (new AnnualLeaveCycleResolver)->forDate($employee, '2025-08-09');
    }

    public function test_february_29_anniversary_uses_february_28_only_in_non_leap_years_and_february_29_in_leap_years(): void
    {
        $employee = $this->employee('2024-02-29');
        $resolver = new AnnualLeaveCycleResolver;

        $beforeFirstObservedAnniversary = $resolver->forDate($employee, '2025-02-27');
        $firstObservedAnniversary = $resolver->forDate($employee, '2025-02-28');
        $secondObservedAnniversary = $resolver->forDate($employee, '2026-02-28');
        $beforeLeapAnniversary = $resolver->forDate($employee, '2028-02-28');
        $leapAnniversary = $resolver->forDate($employee, '2028-02-29');

        $this->assertSame('2024-02-29', $beforeFirstObservedAnniversary->start->toDateString());
        $this->assertSame('2025-02-27', $beforeFirstObservedAnniversary->end->toDateString());
        $this->assertSame('2025-02-28', $firstObservedAnniversary->start->toDateString());
        $this->assertSame('2026-02-27', $firstObservedAnniversary->end->toDateString());
        $this->assertSame('2026-02-28', $secondObservedAnniversary->start->toDateString());
        $this->assertSame('2027-02-27', $secondObservedAnniversary->end->toDateString());
        $this->assertSame('2027-02-28', $beforeLeapAnniversary->start->toDateString());
        $this->assertSame('2028-02-28', $beforeLeapAnniversary->end->toDateString());
        $this->assertSame('2028-02-29', $leapAnniversary->start->toDateString());
        $this->assertSame('2029-02-27', $leapAnniversary->end->toDateString());
        $this->assertSame(4, $leapAnniversary->completedYears);
    }

    private function employee(string $joiningDate, bool $verified = true): Employee
    {
        $employee = new Employee;
        $employee->setRawAttributes([
            'joining_date' => CarbonImmutable::parse($joiningDate)->toDateString(),
            'joining_date_verified_at' => $verified ? '2026-10-20 10:00:00' : null,
        ]);

        return $employee;
    }
}
