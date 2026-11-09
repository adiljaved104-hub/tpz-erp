<?php

namespace App\Filament\Resources\Purchases\RelationManagers;

use App\Services\UserTimezoneResolver;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReceiptsRelationManager extends RelationManager
{
    protected static string $relationship = 'receipts';

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('reference'), TextColumn::make('received_at')->formatStateUsing(fn ($state): ?string => app(UserTimezoneResolver::class)->format($state)), TextColumn::make('receivedBy.name')])->recordActions([ViewAction::make()]);
    }
}
