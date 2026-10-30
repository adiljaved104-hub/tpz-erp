<?php

namespace App\Services\Hr;

use App\Models\Employee;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleAssignment;
use App\Models\WorkScheduleDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EffectiveWorkScheduleResolver
{
    public function assignment(Employee $employee, CarbonImmutable $date): ?WorkScheduleAssignment
    {
        $base = WorkScheduleAssignment::query()
            ->with(['schedule.days', 'schedule.attendancePolicy'])
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->latest('effective_from');

        $explicit = (clone $base)->where('employee_id', $employee->id)->first()
            ?? ($employee->team_id === null ? null : (clone $base)->whereNull('employee_id')->where('team_id', $employee->team_id)->first());
        if ($explicit !== null) {
            return $explicit;
        }

        $version = DB::table('company_work_schedule_versions')->where('effective_from', '<=', $date->toDateString())
            ->orderByDesc('effective_from')->first();
        if ($version === null) {
            return null;
        }
        $schedule = WorkSchedule::query()->with(['days', 'attendancePolicy'])->where('status', true)->find($version->work_schedule_id);
        if ($schedule === null) {
            return null;
        }

        $fallback = new WorkScheduleAssignment([
            'work_schedule_id' => $schedule->id, 'effective_from' => $version->effective_from,
            'effective_to' => null, 'grace_minutes_override' => null,
        ]);
        $fallback->setRelation('schedule', $schedule);

        return $fallback;
    }

    public function graceMinutes(WorkScheduleAssignment $assignment): int
    {
        return (int) ($assignment->grace_minutes_override
            ?? $assignment->schedule?->grace_minutes
            ?? $assignment->schedule?->attendancePolicy?->grace_minutes
            ?? 0);
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
