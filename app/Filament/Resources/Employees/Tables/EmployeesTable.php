<?php

namespace App\Filament\Resources\Employees\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EmployeesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([

                TextColumn::make('employee_id')
                    ->label('Employee ID')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Employee Name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Email copied')
                    ->toggleable(),

                TextColumn::make('phone')
                    ->label('Phone')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Phone copied')
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('team.name')
                    ->label('Team')
                    ->badge()
                    ->color('info')
                    ->placeholder('Not Assigned')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('designation')
                    ->label('Designation')
                    ->searchable(),

                TextColumn::make('role')
                    ->badge()
                    ->colors([
                        'danger' => 'Owner',
                        'warning' => 'Admin',
                        'success' => 'Manager',
                        'gray' => 'Staff',
                    ])
                    ->sortable(),

                IconColumn::make('status')
                    ->label('Active')
                    ->boolean()
                    ->alignCenter(),

                TextColumn::make('joining_date')
                    ->label('Joining Date')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('d M Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime('d M Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),

            ])
            ->defaultSort('employee_id')
            ->searchPlaceholder('Search employees...')

            ->filters([
                //
            ])

            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])

            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}