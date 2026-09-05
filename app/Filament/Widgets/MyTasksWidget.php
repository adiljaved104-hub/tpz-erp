<?php

namespace App\Filament\Widgets;

use App\Enums\TaskAssignmentStatus;
use App\Enums\TaskPermission;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Models\User;
use App\Services\Authorization\TaskAuthorization;
use App\Services\Tasks\TaskLinkedRecordService;
use App\Services\Tasks\TaskQueryService;
use Filament\Widgets\Widget;

class MyTasksWidget extends Widget
{
    protected string $view = 'filament.widgets.my-tasks-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -10;

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->employee?->status === true
            && app(TaskAuthorization::class)->allows($user, TaskPermission::View);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $queries = app(TaskQueryService::class);
        $linkedRecords = app(TaskLinkedRecordService::class);
        $tasks = $queries->personalDashboardTasks($user);
        $contexts = $linkedRecords->contexts($tasks, $user);

        $rows = $tasks->map(function (Task $task) use ($queries, $contexts): array {
            $status = $queries->personalStatus($task);

            return [
                'task' => $task,
                'status' => $status,
                'status_label' => match ($status) {
                    'awaiting_confirmation' => 'Awaiting Confirmation',
                    TaskAssignmentStatus::InProgress->value => 'In Progress',
                    TaskAssignmentStatus::Waiting->value => 'Waiting',
                    default => 'Assigned',
                },
                'status_color' => match ($status) {
                    'awaiting_confirmation', TaskAssignmentStatus::Waiting->value => 'warning',
                    TaskAssignmentStatus::InProgress->value => 'primary',
                    default => 'info',
                },
                'due' => $queries->personalDueLabel($task),
                'overdue' => $status !== 'awaiting_confirmation' && $task->due_at?->isPast() === true,
                'linked' => $contexts[$task->id],
                'url' => TaskResource::getUrl('view', ['record' => $task]),
            ];
        });

        return [
            'summary' => $queries->personalSummary($user),
            'rows' => $rows,
            'viewAllUrl' => TaskResource::getUrl(parameters: ['tab' => 'my_tasks']),
        ];
    }
}
