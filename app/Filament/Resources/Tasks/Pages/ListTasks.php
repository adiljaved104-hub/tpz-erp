<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Enums\TaskPermission;
use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Widgets\TaskSupervisionOverview;
use App\Services\Authorization\TaskAuthorization;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListTasks extends ListRecords
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New Task')];
    }

    protected function getHeaderWidgets(): array
    {
        return [TaskSupervisionOverview::class];
    }

    public function getTabs(): array
    {
        $user = auth()->user();
        $auth = app(TaskAuthorization::class);
        $employee = $user->employee;
        $tabs = [
            'my_tasks' => Tab::make('My Tasks')->modifyQueryUsing(fn (Builder $query) => $query->where(function (Builder $mine) use ($employee): void {
                $mine->whereHas('assignments', fn (Builder $assignment) => $assignment->where('employee_id', $employee->id)->whereNotIn('status', ['cancelled', 'removed']))
                    ->orWhere(fn (Builder $legacy) => $legacy->whereDoesntHave('assignments')->where('assigned_employee_id', $employee->id));
            })),
        ];
        if ($auth->allows($user, TaskPermission::ViewTeam)) {
            $tabs['team_tasks'] = Tab::make('Team Tasks')->modifyQueryUsing(function (Builder $query) use ($auth, $user, $employee): Builder {
                if ($auth->allows($user, TaskPermission::ManageAll)) {
                    return $query->where(fn (Builder $scope) => $scope->whereHas('assignments')->orWhereNotNull('assigned_employee_id')->orWhereNotNull('assigned_team_id'));
                }
                if ($employee->team_id === null) {
                    return $query->whereRaw('1 = 0');
                }

                return $query->where(fn (Builder $scope) => $scope->where('assigned_team_id', $employee->team_id)
                    ->orWhereHas('assignments', fn (Builder $assignment) => $assignment->where('team_id_at_assignment', $employee->team_id)->whereNotIn('status', ['cancelled', 'removed']))
                    ->orWhereHas('assignedEmployee', fn (Builder $employeeQuery) => $employeeQuery->where('team_id', $employee->team_id)));
            });
        }
        if ($auth->allows($user, TaskPermission::ManageAll)) {
            $tabs['unassigned'] = Tab::make('Unassigned')->modifyQueryUsing(fn (Builder $query) => $query->whereDoesntHave('assignments', fn (Builder $assignment) => $assignment->whereNotIn('status', ['removed', 'cancelled']))->whereNull('assigned_employee_id')->whereNull('assigned_team_id'));
        }
        $tabs['pending_assigned'] = Tab::make('Pending / Assigned')->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [TaskStatus::Pending->value, TaskStatus::Assigned->value]));
        $tabs['awaiting_confirmation'] = Tab::make('Awaiting Confirmation')->modifyQueryUsing(fn (Builder $query) => $query->whereHas('completionSubmissions', fn (Builder $submission) => $submission->where('status', 'pending')));
        $tabs['in_progress'] = Tab::make('In Progress')->modifyQueryUsing(fn (Builder $query) => $query->where('status', TaskStatus::InProgress->value));
        $tabs['waiting'] = Tab::make('Waiting')->modifyQueryUsing(fn (Builder $query) => $query->where('status', TaskStatus::Waiting->value));
        $tabs['overdue'] = Tab::make('Overdue')->modifyQueryUsing(fn (Builder $query) => $query->active()->whereNotNull('due_at')->where('due_at', '<', now()));
        $tabs['completed'] = Tab::make('Completed')->modifyQueryUsing(fn (Builder $query) => $query->where('status', TaskStatus::Completed->value));
        if ($auth->allows($user, TaskPermission::ManageAll)) {
            $tabs['all'] = Tab::make('All Tasks');
        }

        return $tabs;
    }
}
