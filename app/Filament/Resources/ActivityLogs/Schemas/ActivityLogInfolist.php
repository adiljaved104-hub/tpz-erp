<?php

namespace App\Filament\Resources\ActivityLogs\Schemas;

use App\Models\ActivityLog;
use App\Support\ActivityLogPresenter;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ActivityLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('event')->label('Event')->formatStateUsing(fn (?string $state): string => ActivityLogPresenter::event($state))->badge(),
            TextEntry::make('event_code')->label('Technical Event Code')->state(fn (ActivityLog $record): string => $record->event),
            TextEntry::make('actor.email')->label('Actor')->placeholder('System'),
            TextEntry::make('subject_type')->label('Subject')->formatStateUsing(fn (?string $state): string => ActivityLogPresenter::subject($state)),
            TextEntry::make('subject_type_code')->label('Technical Subject Type')->state(fn (ActivityLog $record): string => $record->subject_type ?? '—'),
            TextEntry::make('subject_id')->label('Subject ID')->placeholder('-'),
            TextEntry::make('description')->state(fn (ActivityLog $record): string => ActivityLogPresenter::description($record))->columnSpanFull(),
            TextEntry::make('properties')->json()->columnSpanFull(),
            TextEntry::make('ip_address')->placeholder('-'),
            TextEntry::make('user_agent')->placeholder('-')->columnSpanFull(),
            TextEntry::make('created_at')->dateTime('d M Y, h:i A'),
        ]);
    }
}
