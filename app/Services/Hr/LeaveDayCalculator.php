<?php

namespace App\Services\Hr;

use App\Models\CompensatoryOff;
use App\Models\Employee;
use App\Models\PublicHoliday;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Validation\ValidationException;

class LeaveDayCalculator
{
    public function __construct(private readonly EffectiveWorkScheduleResolver $schedules) {}

    /** @return array{days: array<int, array<string, mixed>>, working_days: string} */
    public function calculate(Employee $employee, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($to->isBefore($from)) {
            throw ValidationException::withMessages(['toDate' => 'The end date must be on or after the start date.']);
        }

        $holidays = PublicHoliday::query()->where('status', true)
            ->whereBetween('holiday_date', [$from->toDateString(), $to->toDateString()])
            ->get(['id', 'holiday_date'])->mapWithKeys(fn (PublicHoliday $holiday): array => [$holiday->holiday_date->toDateString() => $holiday->id]);
        $compOffs = CompensatoryOff::query()->where('employee_id', $employee->id)->whereIn('status', ['approved', 'used'])
            ->whereBetween('off_date', [$from->toDateString(), $to->toDateString()])
            ->get(['id', 'off_date'])->mapWithKeys(fn (CompensatoryOff $compOff): array => [$compOff->off_date->toDateString() => $compOff->id]);

        $days = [];
        foreach (CarbonPeriod::create($from, $to) as $mutableDate) {
            $date = CarbonImmutable::instance($mutableDate);
            try {
                $resolved = $this->schedules->day($employee, $date);
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages(['fromDate' => collect($exception->errors())->flatten()->first()]);
            }
            $assignment = $resolved['assignment'];
            $scheduleDay = $resolved['day'];

            $holidayId = $holidays[$date->toDateString()] ?? null;
            $compOffId = $compOffs[$date->toDateString()] ?? null;
            [$classification, $counts, $units] = match (true) {
                $holidayId !== null => ['public_holiday', false, '0.00'],
                $compOffId !== null => ['compensatory_off', false, '0.00'],
                ! $scheduleDay->is_working_day => ['schedule_off', false, '0.00'],
                default => ['working_leave', true, '1.00'],
            };
            $days[] = [
                'leave_date' => $date->toDateString(),
                'work_schedule_id' => $assignment->work_schedule_id,
                'public_holiday_id' => $holidayId,
                'compensatory_off_id' => $compOffId,
                'classification' => $classification,
                'counts_as_leave' => $counts,
                'leave_units' => $units,
            ];
        }

        $workingDays = collect($days)->reduce(fn (string $sum, array $day): string => bcadd($sum, $day['leave_units'], 2), '0.00');
        if (bccomp($workingDays, '0.00', 2) <= 0) {
            throw ValidationException::withMessages(['fromDate' => 'The selected dates contain no configured working days that require leave.']);
        }

        return ['days' => $days, 'working_days' => $workingDays];
    }
}
