<?php

namespace App\Services\Hr;

final class AttendancePenaltyCalculator
{
    /** @return array{late_occurrences: int, absence_equivalent_penalty_days: string} */
    public function calculate(int $lateOccurrences, int $threshold, string $penaltyDays): array
    {
        return [
            'late_occurrences' => $lateOccurrences,
            'absence_equivalent_penalty_days' => bcmul((string) intdiv($lateOccurrences, $threshold), $penaltyDays, 2),
        ];
    }
}
