<?php

namespace App\Filament\Resources\ActivityLogs\Tables;

use App\Models\ActivityLog;
use App\Support\ActivityLogPresenter;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ActivityLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->dateTime('d M Y, h:i A')->sortable(),
                TextColumn::make('event')->label('Event')->formatStateUsing(fn (?string $state): string => ActivityLogPresenter::event($state))->badge()->searchable()->sortable(),
                TextColumn::make('actor.email')->label('Actor')->placeholder('System')->searchable(),
                TextColumn::make('subject_type')->label('Subject')->formatStateUsing(fn (?string $state): string => ActivityLogPresenter::subject($state)),
                TextColumn::make('subject_id')->label('Subject ID')->placeholder('-'),
                TextColumn::make('description')->state(fn (ActivityLog $record): string => ActivityLogPresenter::description($record))->limit(60),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([ViewAction::make()])
            ->toolbarActions([]);
    }
}
