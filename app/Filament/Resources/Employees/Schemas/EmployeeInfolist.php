<?php

namespace App\Filament\Resources\Employees\Schemas;

use App\Models\Employee;
use App\Services\CompanyEmailPolicyService;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EmployeeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Login Information')
                    ->schema([
                        TextEntry::make('user.name')
                            ->label('Login Name')
                            ->placeholder('Legacy Employee — not linked'),
                        TextEntry::make('user.email')
                            ->label('Login Email')
                            ->placeholder('Legacy Employee — not linked'),
                        TextEntry::make('login_account_status')
                            ->label('Account Status')
                            ->state(fn (Employee $record): string => $record->user === null
                                ? 'Not linked'
                                : ($record->status ? 'Active' : 'Inactive'))
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'Active' => 'success',
                                'Inactive' => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('user.email_two_factor_enabled_at')
                            ->label('Email 2FA')
                            ->state(fn (Employee $record): string => $record->user?->email_two_factor_enabled_at ? 'Enabled' : 'Disabled')
                            ->badge()
                            ->color(fn (string $state): string => $state === 'Enabled' ? 'success' : 'gray'),
                        TextEntry::make('company_login_email_status')
                            ->label('Company Email Status')
                            ->state(fn (Employee $record): string => $record->user
                                ? app(CompanyEmailPolicyService::class)->statusFor($record->user)
                                : 'Not linked')
                            ->badge()
                            ->color(fn (string $state): string => $state === 'Approved' ? 'success' : ($state === 'Not linked' ? 'gray' : 'warning')),
                    ])
                    ->columns(5),
                Section::make('Employee Information')
                    ->schema([
                        TextEntry::make('employee_id')->label('Employee ID'),
                        TextEntry::make('name')->label('Full Name'),
                        TextEntry::make('email')->label('Contact Email'),
                        TextEntry::make('phone')->label('Phone Number')->placeholder('Not Provided'),
                        TextEntry::make('team.name')->label('Team')->placeholder('Not Assigned'),
                        TextEntry::make('designation')->label('Designation'),
                        TextEntry::make('role')->badge(),
                        IconEntry::make('status')->label('Active')->boolean(),
                        TextEntry::make('joining_date')->label('Joining Date')->date()->placeholder('-'),
                        TextEntry::make('created_at')->label('Created')->dateTime(),
                        TextEntry::make('updated_at')->label('Last Updated')->dateTime(),
                    ])
                    ->columns(3),
            ]);
    }
}
