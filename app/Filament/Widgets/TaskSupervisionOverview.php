<?php

namespace App\Filament\Widgets;

use App\Enums\TaskPermission;
use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\User;
use App\Services\Authorization\TaskAuthorization;
use App\Services\Tasks\TaskQueryService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class TaskSupervisionOverview extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(TaskAuthorization::class)->allows($user, TaskPermission::ViewTeam);
    }

    protected function getColumns(): int|array
    {
        return ['md' => 3, 'xl' => 6];
    }

    protected function getStats(): array
    {
        $query = app(TaskQueryService::class)->visible(auth()->user());
        $pendingSubmission = fn (Builder $submission) => $submission->where('status', 'pending');

        return [
            $this->card('Pending / Assigned', (clone $query)->whereIn('status', ['pending', 'assigned'])->count(), 'pending_assigned'),
            $this->card('In Progress', (clone $query)->where('status', TaskStatus::InProgress->value)->count(), 'in_progress'),
            $this->card('Waiting', (clone $query)->where('status', TaskStatus::Waiting->value)->count(), 'waiting'),
            $this->card('Awaiting Confirmation', (clone $query)->whereHas('completionSubmissions', $pendingSubmission)->count(), 'awaiting_confirmation', 'warning'),
            $this->card('Overdue', (clone $query)->active()->whereDoesntHave('completionSubmissions', $pendingSubmission)->whereNotNull('due_at')->where('due_at', '<', now())->count(), 'overdue', 'danger'),
            $this->card('Completed', (clone $query)->where('status', TaskStatus::Completed->value)->count(), 'completed', 'success'),
        ];
    }

    private function card(string $label, int $count, string $tab, string $color = 'primary'): Stat
    {
        return Stat::make($label, $count)->color($color)->url(TaskResource::getUrl(parameters: ['tab' => $tab]));
    }
}
