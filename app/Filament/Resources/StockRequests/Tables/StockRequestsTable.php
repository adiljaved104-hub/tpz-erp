<?php

namespace App\Filament\Resources\StockRequests\Tables;

use App\Enums\StockRequestPurpose;
use App\Enums\StockRequestStatus;
use App\Filament\Resources\StockRequests\StockRequestResource;
use App\Models\StockRequest;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StockRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('reference')->searchable()->sortable()->url(fn (StockRequest $record): string => StockRequestResource::getUrl('view', ['record' => $record])),
            TextColumn::make('purpose')->badge(),
            TextColumn::make('order.reference')->label('Order')->placeholder('—')->searchable(),
            TextColumn::make('requester.name')->label('Requested By')->searchable(),
            TextColumn::make('items_count')->label('Products')->numeric(),
            TextColumn::make('status')->badge(),
            TextColumn::make('created_at')->label('Requested At')->dateTime('d M Y, h:i A')->sortable(),
        ])->filters([
            SelectFilter::make('purpose')->options(collect(StockRequestPurpose::cases())->mapWithKeys(fn ($purpose): array => [$purpose->value => $purpose->getLabel()])->all()),
            SelectFilter::make('status')->options(collect(StockRequestStatus::cases())->mapWithKeys(fn ($status): array => [$status->value => $status->getLabel()])->all()),
        ])->recordActions([ViewAction::make()])->defaultSort('id', 'desc')->emptyStateHeading('No Stock Requests found.');
    }
}
