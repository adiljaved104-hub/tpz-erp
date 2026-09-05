<?php

namespace App\Filament\Resources\Tasks\Tables;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Services\Tasks\TaskLinkedRecordService;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TasksTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('reference')->label('Task Ref')->searchable()->sortable(),
            TextColumn::make('title')->searchable()->limit(45)->tooltip(fn (Task $record): string => $record->title),
            TextColumn::make('assignee_display')->label('Assigned To')->state(fn (Task $record): string => $record->assignmentLabel())->wrap(),
            TextColumn::make('status')->badge()->formatStateUsing(fn ($state, Task $record): string => $record->hasPendingCompletion() ? 'Awaiting Confirmation' : $state->getLabel())->color(fn ($state, Task $record): string => $record->hasPendingCompletion() ? 'warning' : $state->getColor())->sortable(),
            TextColumn::make('priority')->badge()->sortable(),
            TextColumn::make('assignment_progress')->label('Recipient Progress')->state(fn (Task $record): string => $record->assignmentProgressLabel())->wrap(),
            TextColumn::make('due_label')->label('Due')->state(fn (Task $record): string => $record->dueLabel() ?? '—')->color(fn (Task $record): string => $record->isOverdue() ? 'danger' : ($record->isDueSoon() ? 'warning' : 'gray')),
            TextColumn::make('linked_record')->label('Linked Record')->state(fn (Task $record): string => app(TaskLinkedRecordService::class)->context($record, auth()->user())['label'])
                ->url(fn (Task $record): ?string => app(TaskLinkedRecordService::class)->context($record, auth()->user())['url']),
            TextColumn::make('latest_update')->label('Latest Update')->state(fn (Task $record): string => $record->latestUpdate
                ? str($record->latestUpdate->note)->limit(70).' · '.$record->latestUpdate->actor->name.' · '.$record->latestUpdate->occurred_at->diffForHumans()
                : '—')->wrap(),
            TextColumn::make('updated_at')->label('Changed')->since()->sortable(),
        ])->filters([SelectFilter::make('status')->options(TaskStatus::class), SelectFilter::make('priority')->options(TaskPriority::class)])
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }
}
