<?php

namespace App\Filament\Resources\Employees\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class EmployeeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([

                TextEntry::make('employee_id')
                    ->label('Employee ID'),

                TextEntry::make('name')
                    ->label('Full Name'),

                TextEntry::make('email')
                    ->label('Email Address'),

                TextEntry::make('phone')
                    ->label('Phone Number')
                    ->placeholder('Not Provided'),

                TextEntry::make('team.name')
                    ->label('Team')
                    ->placeholder('Not Assigned'),

                TextEntry::make('designation')
                    ->label('Designation'),

                TextEntry::make('role')
                    ->badge(),

                IconEntry::make('status')
                    ->label('Active')
                    ->boolean(),

                TextEntry::make('joining_date')
                    ->label('Joining Date')
                    ->date()
                    ->placeholder('-'),

                TextEntry::make('created_at')
                    ->label('Created')
                    ->dateTime(),

                TextEntry::make('updated_at')
                    ->label('Last Updated')
                    ->dateTime(),

            ]);
    }
}