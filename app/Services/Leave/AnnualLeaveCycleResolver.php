<?php

namespace App\Services\Leave;

use App\DTOs\Leave\LeaveCycle;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class AnnualLeaveCycleResolver
{
    public function forDate(Employee $employee, CarbonImmutable|string $date): LeaveCycle
    {
        $joiningDate = $employee->joining_date === null ? null : CarbonImmutable::parse($employee->joining_date)->startOfDay();
        if ($joiningDate === null || $employee->joining_date_verified_at === null) {
            throw ValidationException::withMessages(['joining_date' => 'The employee joining date must be verified before an annual leave balance can be calculated.']);
        }

        $date = $date instanceof CarbonImmutable ? $date->startOfDay() : CarbonImmutable::parse($date)->startOfDay();
        if ($date->lt($joiningDate)) {
            throw ValidationException::withMessages(['from_date' => 'Leave cannot be recorded before the employee’s verified joining date.']);
        }

        $years = $date->year - $joiningDate->year;
        $start = $this->anniversary($joiningDate, $joiningDate->year + $years);
        if ($start->gt($date)) {
            $years--;
            $start = $this->anniversary($joiningDate, $joiningDate->year + $years);
        }
        $end = $this->anniversary($joiningDate, $joiningDate->year + $years + 1)->subDay();

        return new LeaveCycle($start, $end, $years);
    }

    /** @return array<int, LeaveCycle> */
    public function cyclesForRange(Employee $employee, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $cycles = [];
        $cursor = $from;
        while ($cursor->lte($to)) {
            $cycle = $this->forDate($employee, $cursor);
            $cycles[$cycle->start->toDateString()] = $cycle;
            $cursor = $cycle->end->addDay();
        }

        return array_values($cycles);
    }

    private function anniversary(CarbonImmutable $joiningDate, int $year): CarbonImmutable
    {
        $month = (int) $joiningDate->format('m');
        $day = (int) $joiningDate->format('d');
        if (! checkdate($month, $day, $year)) {
            // A 29 February joining date observes its anniversary on 28 February
            // in non-leap years; in leap years the normal 29 February date applies.
            $day = 28;
        }

        return CarbonImmutable::create($year, $month, $day)->startOfDay();
    }
}
