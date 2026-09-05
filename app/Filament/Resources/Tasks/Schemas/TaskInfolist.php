<?php

namespace App\Filament\Resources\Tasks\Schemas;

use App\Models\Task;
use App\Services\Tasks\TaskLinkedRecordService;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TaskInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Main Details')->columns(3)->schema([
                TextEntry::make('reference')->label('Task Ref'), TextEntry::make('status')->badge(), TextEntry::make('priority')->badge(),
                TextEntry::make('title')->columnSpan(2), TextEntry::make('assignee_display')->label('Assigned To')->state(fn (Task $record): string => $record->assignmentLabel()),
                TextEntry::make('effective_status')->label('Effective State')->state(fn (Task $record): string => $record->hasPendingCompletion() ? 'Awaiting Confirmation' : $record->status->getLabel())->badge(),
                TextEntry::make('assignment_progress')->label('Assignment Progress')->state(fn (Task $record): string => $record->assignmentProgressLabel()),
                TextEntry::make('due_at')->label('Due')->dateTime('d M Y, h:i A')->placeholder('—'),
                TextEntry::make('due_label')->label('Time Remaining')->state(fn (Task $record): string => $record->dueLabel() ?? 'No due date'),
                TextEntry::make('createdBy.name')->label('Created By'),
                TextEntry::make('linked_record')->label('Linked Record')->state(fn (Task $record): string => app(TaskLinkedRecordService::class)->context($record, auth()->user())['label'])
                    ->url(fn (Task $record): ?string => app(TaskLinkedRecordService::class)->context($record, auth()->user())['url']),
                TextEntry::make('description')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Timeline')->schema([
                RepeatableEntry::make('events')->label('')->schema([
                    TextEntry::make('event_type')->label('Event')->formatStateUsing(fn ($state): string => $state->label())->badge(),
                    TextEntry::make('occurred_at')->label('Date / Time')->dateTime('d M Y, h:i A'),
                    TextEntry::make('actor.name')->label('Actor'), TextEntry::make('note')->placeholder('—'),
                ])->table([TableColumn::make('Event'), TableColumn::make('Date / Time'), TableColumn::make('Actor'), TableColumn::make('Note / Update')])->columnSpanFull(),
            ]),
        ]);
    }
}
