<?php

namespace App\Filament\Resources\Employees\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class EmployeeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([

                TextInput::make('employee_id')
                    ->label('Employee ID')
                    ->disabled()
                    ->dehydrated(false)
                    ->placeholder('Auto Generated'),

                TextInput::make('name')
                    ->label('Full Name')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('Enter employee name'),

                TextInput::make('email')
                    ->label('Email Address')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->placeholder('example@company.com'),

                TextInput::make('password')
                    ->label('Password')
                    ->password()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn ($state): bool => filled($state))
                    ->placeholder('Enter password'),

                TextInput::make('phone')
                    ->label('Phone Number')
                    ->tel()
                    ->placeholder('+971XXXXXXXXX'),

                Select::make('team_id')
                    ->label('Team')
                    ->relationship('team', 'name')
                    ->searchable()
                    ->preload(),

                TextInput::make('designation')
                    ->label('Designation')
                    ->required()
                    ->placeholder('Sales Executive'),

                Select::make('role')
                    ->label('Role')
                    ->options([
                        'Owner' => 'Owner',
                        'Admin' => 'Admin',
                        'Manager' => 'Manager',
                        'Staff' => 'Staff',
                    ])
                    ->default('Staff')
                    ->required(),

                Toggle::make('status')
                    ->label('Active')
                    ->default(true),

                DatePicker::make('joining_date')
                    ->label('Joining Date'),

            ]);
    }
}