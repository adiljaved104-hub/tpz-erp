<?php

namespace App\Filament\Resources\PurchaseReceipts\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PurchaseReceiptsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('reference')->label('GRN')->searchable()->sortable(), TextColumn::make('purchase.reference')->label('Purchase')->searchable(),
            TextColumn::make('purchase.supplier.name')->label('Supplier')->placeholder('No Supplier'), TextColumn::make('warehouse.code')->label('Location'),
            TextColumn::make('received_at')->dateTime('d M Y, h:i A')->sortable(), TextColumn::make('receivedBy.name')->label('Received By'),
        ])->recordActions([ViewAction::make()]);
    }
}
