<?php

namespace App\Services\Hr;

use App\Models\Employee;
use App\Models\WorkScheduleAssignment;
use App\Models\WorkScheduleDay;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class EffectiveWorkScheduleResolver
{
    public function assignment(Employee $employee, CarbonImmutable $date): ?WorkScheduleAssignment
    {
        $base = WorkScheduleAssignment::query()
            ->with('schedule.days')
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->latest('effective_from');

        return (clone $base)->where('employee_id', $employee->id)->first()
            ?? ($employee->team_id === null ? null : (clone $base)->whereNull('employee_id')->where('team_id', $employee->team_id)->first());
    }

    /** @return array{assignment: WorkScheduleAssignment, day: WorkScheduleDay} */
    public function day(Employee $employee, CarbonImmutable $date): array
    {
        $assignment = $this->assignment($employee, $date);
        if ($assignment === null) {
            throw ValidationException::withMessages(['schedule' => "No Work Schedule covers {$date->format('d M Y')}."]);
        }
        $cycleLength = max(1, (int) $assignment->schedule->cycle_length_weeks);
        $weeksSinceStart = (int) $assignment->effective_from
    ->startOfWeek()
    ->startOfDay()
    ->diffInWeeks(
        $date->startOfWeek()->startOfDay()
    );

$cycleWeek = ($weeksSinceStart % $cycleLength) + 1;
        $day = $assignment->schedule->days->first(fn (WorkScheduleDay $candidate): bool => (int) $candidate->cycle_week === $cycleWeek
            && (int) $candidate->weekday === $date->dayOfWeek);
        if ($day === null) {
            throw ValidationException::withMessages(['schedule' => "The effective Work Schedule has no pattern for {$date->format('l')}."]);
        }

        return compact('assignment', 'day');
    }
}
