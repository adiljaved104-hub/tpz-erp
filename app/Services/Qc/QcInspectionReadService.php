<?php

namespace App\Services\Qc;

use App\Enums\QcInspectionStatus;
use App\Enums\QcPermission;
use App\Models\User;
use App\Services\Authorization\QcAuthorization;
use App\Services\BusinessTimezone;
use Illuminate\Database\Eloquent\Builder;

class QcInspectionReadService
{
    public function __construct(
        private readonly QcInspectionService $inspections,
        private readonly QcAuthorization $authorization,
        private readonly BusinessTimezone $timezone,
    ) {}

    public function query(User $actor, string $scope): Builder
    {
        $query = $this->inspections->visible($actor);

        return match ($scope) {
            'pending' => $query->where('status', QcInspectionStatus::Pending->value),
            'mine' => $query->where('technician_user_id', $actor->id)
                ->whereIn('status', [QcInspectionStatus::InProgress->value, QcInspectionStatus::Rework->value]),
            'completed_today' => $this->completedToday($query),
        };
    }

    /** @return array{pending: int, my_in_progress: int, completed_today: int} */
    public function counters(User $actor): array
    {
        if (! $this->authorization->allows($actor, QcPermission::View)) {
            return ['pending' => 0, 'my_in_progress' => 0, 'completed_today' => 0];
        }

        return [
            'pending' => $this->query($actor, 'pending')->count(),
            'my_in_progress' => $this->query($actor, 'mine')->count(),
            'completed_today' => $this->query($actor, 'completed_today')->count(),
        ];
    }

    private function completedToday(Builder $query): Builder
    {
        $start = $this->timezone->date(now())->startOfDay();
        $end = $start->addDay();

        return $query->where('status', QcInspectionStatus::Completed->value)
            ->where('completed_at', '>=', $start->setTimezone('UTC'))
            ->where('completed_at', '<', $end->setTimezone('UTC'));
    }
}
