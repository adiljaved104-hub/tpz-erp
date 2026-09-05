<?php

namespace App\Filament\Resources\Warehouses\Tables;

use App\Actions\Warehouses\SetDefaultWarehouse;
use App\Actions\Warehouses\SetWarehouseStatus;
use App\DTOs\Warehouses\ChangeDefaultWarehouseData;
use App\DTOs\Warehouses\ChangeWarehouseStatusData;
use App\Enums\InventoryLocationType;
use App\Models\Warehouse;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class WarehousesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->badge()->searchable()->sortable(),
                TextColumn::make('location_type')->label('Type')->badge()->sortable(),
                TextColumn::make('marketplacePlatform.name')->label('Platform')->placeholder('-')->sortable(),
                TextColumn::make('fulfillment_tag')->label('Fulfilment Tag')->placeholder('-')->toggleable(),
                TextColumn::make('address')->searchable()->limit(50)->placeholder('-'),
                IconColumn::make('status')->label('Active')->boolean()->sortable(),
                IconColumn::make('is_default')->label('Default')->boolean()->sortable(),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('status')->label('Active'),
                TernaryFilter::make('is_default')->label('Default'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('changeStatus')
                    ->label(fn (Warehouse $record): string => $record->status ? 'Deactivate' : 'Activate')
                    ->color(fn (Warehouse $record): string => $record->status ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->schema([Textarea::make('reason')->required()->maxLength(1000)])
                    ->authorize(fn (Warehouse $record): bool => auth()->user()->can('changeStatus', $record))
                    ->action(fn (Warehouse $record, array $data): Warehouse => app(SetWarehouseStatus::class)->handle(
                        $record,
                        new ChangeWarehouseStatusData(! $record->status, $data['reason']),
                        auth()->user(),
                    )),
                Action::make('makeDefault')
                    ->label('Make Default')
                    ->color('warning')
                    ->visible(fn (Warehouse $record): bool => ! $record->is_default && $record->location_type === InventoryLocationType::CompanyWarehouse)
                    ->requiresConfirmation()
                    ->schema([Textarea::make('reason')->required()->maxLength(1000)])
                    ->authorize(fn (Warehouse $record): bool => auth()->user()->can('changeDefault', $record))
                    ->action(fn (Warehouse $record, array $data): Warehouse => app(SetDefaultWarehouse::class)->handle(
                        $record,
                        new ChangeDefaultWarehouseData($data['reason']),
                        auth()->user(),
                    )),
            ])
            ->toolbarActions([]);
    }
}
