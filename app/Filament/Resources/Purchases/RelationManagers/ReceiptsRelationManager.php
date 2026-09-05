<?php

namespace App\Filament\Resources\Purchases\RelationManagers;

use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReceiptsRelationManager extends RelationManager
{
    protected static string $relationship = 'receipts';

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('reference'), TextColumn::make('received_at')->dateTime(), TextColumn::make('receivedBy.name')])->recordActions([ViewAction::make()]);
    }
}
