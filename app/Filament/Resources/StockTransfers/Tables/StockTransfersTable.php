<?php

namespace App\Filament\Resources\StockTransfers\Tables;

use App\Enums\StockTransferStatus;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Models\StockTransfer;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StockTransfersTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('reference')->searchable()->sortable()->url(fn (StockTransfer $record): string => StockTransferResource::getUrl('view', ['record' => $record])), TextColumn::make('sourceWarehouse.name')->label('From'), TextColumn::make('destinationWarehouse.name')->label('To'), TextColumn::make('status')->badge(),
            TextColumn::make('transfer_date')->date('d M Y')->sortable(), TextColumn::make('displayItems')->label('Products / Qty')->state(fn ($record): string => $record->displayItems->map(fn ($item): string => "{$item->sku} × {$item->quantity}")->join(', '))->limit(64)->tooltip(fn (StockTransfer $record): string => $record->displayItems->map(fn ($item): string => "{$item->sku} × {$item->quantity}")->join(', ')), TextColumn::make('handledBy.name')->label('Handled By')->placeholder('—'),
        ])->filters([SelectFilter::make('status')->options(collect(StockTransferStatus::cases())->mapWithKeys(fn ($status): array => [$status->value => $status->getLabel()])->all())])->recordActions([ViewAction::make()])
            ->emptyStateHeading('No Stock Transfers found for the selected filters.');
    }
}
