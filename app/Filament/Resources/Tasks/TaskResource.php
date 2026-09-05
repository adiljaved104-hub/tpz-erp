<?php

namespace App\Filament\Resources\Tasks;

use App\Enums\TaskPermission;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Filament\Resources\Tasks\Schemas\TaskInfolist;
use App\Filament\Resources\Tasks\Tables\TasksTable;
use App\Models\Task;
use App\Services\Authorization\TaskAuthorization;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TaskResource extends Resource
{
    protected static ?string $model = Task::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|\UnitEnum|null $navigationGroup = 'Work';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function form(Schema $schema): Schema
    {
        return TaskForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TaskInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TasksTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(TaskPermission::View->value) === true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can(TaskPermission::Create->value) === true;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view', $record) === true;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('update', $record) === true && ! $record->status->isTerminal();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return $user ? app(TaskAuthorization::class)->scopeQuery(parent::getEloquentQuery(), $user)
            ->with(['assignments.employee:id,name', 'completionSubmissions', 'assignedTeam:id,name', 'latestUpdate.actor:id,name'])
            : parent::getEloquentQuery()->whereRaw('1=0');
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return Task::query();
    }

    public static function getPages(): array
    {
        return ['index' => ListTasks::route('/'), 'create' => CreateTask::route('/create'), 'view' => ViewTask::route('/{record}'), 'edit' => EditTask::route('/{record}/edit')];
    }
}
