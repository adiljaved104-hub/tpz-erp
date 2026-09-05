<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Enums\TaskAssignmentMode;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Services\Tasks\TaskService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditTask extends EditRecord
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Task $record */
        $task = app(TaskService::class)->update($record, ['title' => $data['title'], 'description' => $data['description'] ?? null, 'priority' => $data['priority'], 'due_at' => $data['due_at'] ?? null], auth()->user());

        $mode = $data['assignment_mode'] instanceof TaskAssignmentMode ? $data['assignment_mode'] : TaskAssignmentMode::from($data['assignment_mode']);
        $employeeIds = match ($mode) {
            TaskAssignmentMode::SingleEmployee => [(int) $data['assigned_employee_id']],
            TaskAssignmentMode::MultipleEmployees => array_map('intval', $data['assigned_employee_ids'] ?? []),
            default => [],
        };

        return app(TaskService::class)->assignByMode($task, $mode, $employeeIds, filled($data['assigned_team_id'] ?? null) ? (int) $data['assigned_team_id'] : null, auth()->user());
    }
}
