<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\DTOs\Tasks\CreateTaskData;
use App\Enums\TaskAssignmentMode;
use App\Enums\TaskPriority;
use App\Filament\Resources\Tasks\TaskResource;
use App\Services\Tasks\TaskService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTask extends CreateRecord
{
    protected static string $resource = TaskResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        $mode = $data['assignment_mode'] instanceof TaskAssignmentMode ? $data['assignment_mode'] : TaskAssignmentMode::from($data['assignment_mode']);
        $employeeIds = match ($mode) {
            TaskAssignmentMode::SingleEmployee => [(int) $data['assigned_employee_id']],
            TaskAssignmentMode::MultipleEmployees => array_map('intval', $data['assigned_employee_ids'] ?? []),
            default => [],
        };

        return app(TaskService::class)->create(new CreateTaskData(
            title: $data['title'], description: $data['description'] ?? null,
            priority: $data['priority'] instanceof TaskPriority ? $data['priority'] : TaskPriority::from($data['priority']),
            assignedEmployeeId: null,
            assignedTeamId: filled($data['assigned_team_id'] ?? null) ? (int) $data['assigned_team_id'] : null,
            dueAt: filled($data['due_at'] ?? null) ? (string) $data['due_at'] : null, followUpAt: null,
            linkedType: null, linkedRecordId: null, idempotencyKey: $data['idempotency_key'],
            assignedEmployeeIds: $employeeIds,
            assignmentMode: $mode,
        ), auth()->user());
    }
}
