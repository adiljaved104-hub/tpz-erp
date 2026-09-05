<?php

namespace App\Filament\Resources\Suppliers\Tables;

use App\Actions\Suppliers\SetSupplierStatus;
use App\DTOs\Suppliers\ChangeSupplierStatusData;
use App\Models\Supplier;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class SuppliersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('contact_person')->label('Contact Person')->searchable()->placeholder('-'),
                TextColumn::make('phone')->searchable()->placeholder('-'),
                TextColumn::make('email')->searchable()->placeholder('-'),
                TextColumn::make('vat_number')->label('VAT Number')->searchable()->placeholder('-')->toggleable(),
                IconColumn::make('status')->label('Active')->boolean()->sortable(),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('status')->label('Active'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('changeStatus')
                    ->label(fn (Supplier $record): string => $record->status ? 'Deactivate' : 'Activate')
                    ->color(fn (Supplier $record): string => $record->status ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->schema([
                        Textarea::make('reason')->required()->maxLength(1000),
                    ])
                    ->authorize(fn (Supplier $record): bool => auth()->user()->can('changeStatus', $record))
                    ->action(fn (Supplier $record, array $data): Supplier => app(SetSupplierStatus::class)->handle(
                        $record,
                        new ChangeSupplierStatusData(! $record->status, $data['reason']),
                        auth()->user(),
                    )),
            ])
            ->toolbarActions([]);
    }
}
