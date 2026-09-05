<?php

namespace App\Filament\Actions;

use App\DTOs\Tasks\CreateTaskData;
use App\Enums\TaskAssignmentMode;
use App\Enums\TaskLinkedType;
use App\Enums\TaskPriority;
use App\Models\Task;
use App\Services\Tasks\TaskAssigneeService;
use App\Services\Tasks\TaskService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CreateTaskFromSourceAction
{
    public static function make(TaskLinkedType $type, Model $record): Action
    {
        $context = (new Task)->forceFill(['linked_type' => $type, 'linked_record_id' => $record->getKey()]);
        $reference = $record->getAttribute('reference') ?? $record->getAttribute('sku') ?? $record->getAttribute('employee_id');

        return Action::make('createTask')->label('Create Task')->icon('heroicon-o-clipboard-document-list')
            ->visible(fn (): bool => auth()->user()->can('task.create'))
            ->schema([
                TextInput::make('title')->default("Follow up {$reference}")->required()->maxLength(255),
                Select::make('assignment_mode')->label('Assign To')->options(TaskAssignmentMode::class)->default(TaskAssignmentMode::SingleEmployee)->required()->live()
                    ->afterStateUpdated(function (Set $set, $state): void {
                        $mode = $state instanceof TaskAssignmentMode ? $state : TaskAssignmentMode::tryFrom((string) $state);
                        if ($mode !== TaskAssignmentMode::SingleEmployee) {
                            $set('assigned_employee_id', null);
                        }
                        if ($mode !== TaskAssignmentMode::MultipleEmployees) {
                            $set('assigned_employee_ids', []);
                        }
                        if (! in_array($mode, [TaskAssignmentMode::EntireTeam, TaskAssignmentMode::TeamQueue], true)) {
                            $set('assigned_team_id', null);
                        }
                    }),
                Select::make('assigned_employee_id')->label('Employee')->options(fn (): array => app(TaskAssigneeService::class)->options($context))->searchable()
                    ->required(fn (Get $get): bool => self::mode($get('assignment_mode')) === TaskAssignmentMode::SingleEmployee)
                    ->visible(fn (Get $get): bool => self::mode($get('assignment_mode')) === TaskAssignmentMode::SingleEmployee),
                Select::make('assigned_employee_ids')->label('Employees')->multiple()->options(fn (): array => app(TaskAssigneeService::class)->options($context))->searchable()->minItems(2)
                    ->required(fn (Get $get): bool => self::mode($get('assignment_mode')) === TaskAssignmentMode::MultipleEmployees)
                    ->visible(fn (Get $get): bool => self::mode($get('assignment_mode')) === TaskAssignmentMode::MultipleEmployees),
                Select::make('assigned_team_id')->label('Team')->options(fn (): array => app(TaskAssigneeService::class)->teamOptions())->searchable()->live()
                    ->required(fn (Get $get): bool => in_array(self::mode($get('assignment_mode')), [TaskAssignmentMode::EntireTeam, TaskAssignmentMode::TeamQueue], true))
                    ->visible(fn (Get $get): bool => in_array(self::mode($get('assignment_mode')), [TaskAssignmentMode::EntireTeam, TaskAssignmentMode::TeamQueue], true)),
                Placeholder::make('entire_team_preview')->label('Recipient Preview')
                    ->content(function (Get $get): string {
                        $count = app(TaskAssigneeService::class)->eligibleTeamMemberCount(filled($get('assigned_team_id')) ? (int) $get('assigned_team_id') : null, auth()->user());

                        return $count.' '.str('employee')->plural($count).' will receive this Task.';
                    })->visible(fn (Get $get): bool => self::mode($get('assignment_mode')) === TaskAssignmentMode::EntireTeam),
                Select::make('priority')->options(TaskPriority::class)->default(TaskPriority::Normal)->required(),
                DateTimePicker::make('due_at')->label('Due Date / Time')->seconds(false), Textarea::make('description')->label('Note')->rows(3),
            ])->action(function (array $data) use ($type, $record): void {
                $mode = self::mode($data['assignment_mode']);
                $employeeIds = match ($mode) {
                    TaskAssignmentMode::SingleEmployee => [(int) $data['assigned_employee_id']],
                    TaskAssignmentMode::MultipleEmployees => array_map('intval', $data['assigned_employee_ids'] ?? []),
                    default => [],
                };
                app(TaskService::class)->create(new CreateTaskData(
                    $data['title'], $data['description'] ?? null,
                    $data['priority'] instanceof TaskPriority ? $data['priority'] : TaskPriority::from($data['priority']),
                    null,
                    filled($data['assigned_team_id'] ?? null) ? (int) $data['assigned_team_id'] : null,
                    $data['due_at'] ?? null, null, $type, (int) $record->getKey(), (string) Str::uuid(),
                    $employeeIds, $mode,
                ), auth()->user());
                Notification::make()->success()->title('Task created')->send();
            });
    }

    private static function mode(mixed $state): TaskAssignmentMode
    {
        return $state instanceof TaskAssignmentMode ? $state : TaskAssignmentMode::from((string) $state);
    }
}
