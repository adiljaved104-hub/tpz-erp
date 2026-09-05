<?php

namespace App\Filament\Resources\Tasks\Schemas;

use App\Enums\TaskAssignmentMode;
use App\Enums\TaskPriority;
use App\Models\Task;
use App\Services\Tasks\TaskAssigneeService;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class TaskForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Task')->columns(2)->schema([
                TextInput::make('title')->required()->maxLength(255)->columnSpanFull(),
                Select::make('assignment_mode')->label('Assign To')->options(TaskAssignmentMode::class)
                    ->default(TaskAssignmentMode::SingleEmployee)->required()->live()
                    ->afterStateHydrated(fn (Select $component, ?Task $record) => $component->state(self::modeFor($record)->value))
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
                Select::make('assigned_employee_id')->label('Employee')->searchable()
                    ->options(fn (?Task $record): array => app(TaskAssigneeService::class)->options($record))
                    ->afterStateHydrated(fn (Select $component, ?Task $record) => $component->state(count(self::employeeIds($record)) === 1 ? self::employeeIds($record)[0] : null))
                    ->required(fn (Get $get): bool => self::modeValue($get('assignment_mode')) === TaskAssignmentMode::SingleEmployee->value)
                    ->visible(fn (Get $get): bool => self::modeValue($get('assignment_mode')) === TaskAssignmentMode::SingleEmployee->value),
                Select::make('assigned_employee_ids')->label('Employees')->multiple()->searchable()
                    ->options(fn (?Task $record): array => app(TaskAssigneeService::class)->options($record))
                    ->afterStateHydrated(fn (Select $component, ?Task $record) => $component->state(count(self::employeeIds($record)) > 1 ? self::employeeIds($record) : []))
                    ->required(fn (Get $get): bool => self::modeValue($get('assignment_mode')) === TaskAssignmentMode::MultipleEmployees->value)
                    ->minItems(2)
                    ->visible(fn (Get $get): bool => self::modeValue($get('assignment_mode')) === TaskAssignmentMode::MultipleEmployees->value)
                    ->helperText('Each selected Employee receives an independent assignment.'),
                Select::make('assigned_team_id')->label('Team')->searchable()
                    ->options(fn (): array => app(TaskAssigneeService::class)->teamOptions())
                    ->afterStateHydrated(fn (Select $component, ?Task $record) => $component->state(self::teamIdFor($record)))
                    ->required(fn (Get $get): bool => in_array(self::modeValue($get('assignment_mode')), [TaskAssignmentMode::EntireTeam->value, TaskAssignmentMode::TeamQueue->value], true))
                    ->visible(fn (Get $get): bool => in_array(self::modeValue($get('assignment_mode')), [TaskAssignmentMode::EntireTeam->value, TaskAssignmentMode::TeamQueue->value], true))
                    ->live()
                    ->helperText(fn (Get $get): string => self::modeValue($get('assignment_mode')) === TaskAssignmentMode::TeamQueue->value
                        ? 'The Task stays in Team Work until a named Employee is assigned.'
                        : 'Every active eligible Team member receives an independent assignment.'),
                Placeholder::make('entire_team_preview')->label('Recipient Preview')
                    ->content(function (Get $get): string {
                        $count = app(TaskAssigneeService::class)->eligibleTeamMemberCount(filled($get('assigned_team_id')) ? (int) $get('assigned_team_id') : null, auth()->user());

                        return $count.' '.str('employee')->plural($count).' will receive this Task.';
                    })
                    ->visible(fn (Get $get): bool => self::modeValue($get('assignment_mode')) === TaskAssignmentMode::EntireTeam->value),
                Select::make('priority')->options(TaskPriority::class)->default(TaskPriority::Normal)->required(),
                DateTimePicker::make('due_at')->label('Due Date / Time')->seconds(false),
                Textarea::make('description')->rows(4)->columnSpanFull(),
                Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid()),
            ]),
        ]);
    }

    private static function modeFor(?Task $task): TaskAssignmentMode
    {
        if ($task?->assigned_team_id !== null) {
            return TaskAssignmentMode::TeamQueue;
        }

        $employeeIds = self::employeeIds($task);
        if (count($employeeIds) > 1) {
            $teamIds = $task?->assignments()->whereNotIn('status', ['removed', 'cancelled'])->pluck('team_id_at_assignment')->filter()->unique()->values()->all() ?? [];
            if (count($teamIds) === 1) {
                $eligibleIds = app(TaskAssigneeService::class)->eligibleTeamMemberIds((int) $teamIds[0], auth()->user());
                sort($eligibleIds);
                $selectedIds = $employeeIds;
                sort($selectedIds);
                if ($eligibleIds === $selectedIds) {
                    return TaskAssignmentMode::EntireTeam;
                }
            }

            return TaskAssignmentMode::MultipleEmployees;
        }

        return TaskAssignmentMode::SingleEmployee;
    }

    /** @return array<int, int> */
    private static function employeeIds(?Task $task): array
    {
        return $task?->assignments()->whereNotIn('status', ['removed', 'cancelled'])->pluck('employee_id')->map(fn ($id): int => (int) $id)->all() ?? [];
    }

    private static function modeValue(mixed $state): string
    {
        return $state instanceof TaskAssignmentMode ? $state->value : (string) $state;
    }

    private static function teamIdFor(?Task $task): ?int
    {
        if ($task?->assigned_team_id !== null) {
            return (int) $task->assigned_team_id;
        }
        if (self::modeFor($task) !== TaskAssignmentMode::EntireTeam) {
            return null;
        }

        $teamId = $task?->assignments()->whereNotIn('status', ['removed', 'cancelled'])->value('team_id_at_assignment');

        return $teamId === null ? null : (int) $teamId;
    }
}
