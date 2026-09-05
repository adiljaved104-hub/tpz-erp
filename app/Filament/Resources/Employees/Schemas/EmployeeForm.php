<?php

namespace App\Filament\Resources\Employees\Schemas;

use App\Enums\EmployeeRole;
use App\Filament\Pages\ChangeLoginEmail;
use App\Filament\Pages\RecoverLoginEmail;
use App\Models\Employee;
use App\Services\CompanyEmailPolicyService;
use App\Services\LoginEmailChangeService;
use App\Services\LoginEmailRecoveryService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Illuminate\Validation\Rules\Password;

class EmployeeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Login Information')
                    ->schema([
                        TextInput::make('email')
                            ->label('Login Email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique('users', 'email')
                            ->unique('employees', 'email')
                            ->visibleOn('create'),
                        TextInput::make('password')
                            ->label('Password')
                            ->password()
                            ->required()
                            ->confirmed()
                            ->rule(Password::defaults())
                            ->revealable()
                            ->visibleOn('create'),
                        TextInput::make('password_confirmation')
                            ->label('Password Confirmation')
                            ->password()
                            ->required()
                            ->dehydrated(false)
                            ->visibleOn('create'),
                        Placeholder::make('linked_login_account')
                            ->label('Linked Login Account')
                            ->content(fn (?Employee $record): string => $record?->user === null
                                ? 'Legacy Employee — no linked User'
                                : "{$record->user->name} \u{2014} {$record->user->email}")
                            ->visibleOn('edit'),
                        Placeholder::make('current_login_email')
                            ->label('Current Login Email')
                            ->content(fn (?Employee $record): string => $record?->user?->email ?? '—')
                            ->visibleOn('edit'),
                        Placeholder::make('company_email_status')
                            ->label('Company Email Status')
                            ->content(fn (?Employee $record): string => $record?->user === null
                                ? 'Not Linked'
                                : app(CompanyEmailPolicyService::class)->statusFor($record->user))
                            ->visibleOn('edit'),
                        Placeholder::make('two_factor_status')
                            ->label('2FA Status')
                            ->content(fn (?Employee $record): string => $record?->user?->email_two_factor_enabled_at === null
                                ? 'Disabled'
                                : 'Enabled')
                            ->visibleOn('edit'),
                        Placeholder::make('login_email_recovery_guidance')
                            ->label('Recovery Guidance')
                            ->content('Use recovery only when the employee cannot access the current mailbox.')
                            ->visible(fn (?Employee $record): bool => $record?->user !== null
                                && DatabaseSchema::hasTable('login_email_recovery_requests')
                                && auth()->user() !== null
                                && app(LoginEmailRecoveryService::class)->allows(auth()->user(), $record->user))
                            ->visibleOn('edit'),
                        Actions::make([
                            Action::make('changeLoginEmail')
                                ->label('Change Login Email')
                                ->icon('heroicon-o-envelope')
                                ->url(fn (?Employee $record): string => ChangeLoginEmail::getUrl(['employee' => $record?->id]))
                                ->visible(fn (?Employee $record): bool => $record?->user !== null
                                    && DatabaseSchema::hasTable('login_email_change_requests')
                                    && auth()->user() !== null
                                    && app(LoginEmailChangeService::class)->allows(auth()->user(), $record->user)),
                            Action::make('recoverLoginEmail')
                                ->label('Recover Login Email')
                                ->icon('heroicon-o-shield-exclamation')
                                ->color('danger')
                                ->outlined()
                                ->url(fn (?Employee $record): string => RecoverLoginEmail::getUrl(['employee' => $record?->id]))
                                ->visible(fn (?Employee $record): bool => $record?->user !== null
                                    && DatabaseSchema::hasTable('login_email_recovery_requests')
                                    && auth()->user() !== null
                                    && app(LoginEmailRecoveryService::class)->allows(auth()->user(), $record->user)),
                        ])
                            ->alignStart()
                            ->columnSpanFull()
                            ->visibleOn('edit'),
                    ])
                    ->columns(2),
                Section::make('Employee Information')
                    ->schema([
                        TextInput::make('name')
                            ->label('Full Name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Enter employee name'),
                        TextInput::make('employee_id')
                            ->label('Employee ID')
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder('Generated automatically'),
                        TextInput::make('phone')
                            ->label('Phone Number')
                            ->tel()
                            ->maxLength(255)
                            ->placeholder('+971XXXXXXXXX'),
                        Select::make('team_id')
                            ->label('Team')
                            ->relationship('team', 'name')
                            ->searchable()
                            ->preload(),
                        TextInput::make('designation')
                            ->label('Designation')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Sales Executive'),
                        Select::make('role')
                            ->label('Role')
                            ->options(fn (?Employee $record): array => self::roleOptions($record))
                            ->default(EmployeeRole::Staff->value)
                            ->required(),
                        DatePicker::make('joining_date')
                            ->label('Joining Date'),
                        Toggle::make('status')
                            ->label('Active')
                            ->default(true)
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }

    /** @return array<string, string> */
    private static function roleOptions(?Employee $record): array
    {
        return collect(EmployeeRole::cases())
            ->reject(fn (EmployeeRole $role): bool => $record === null && $role === EmployeeRole::Owner)
            ->mapWithKeys(fn (EmployeeRole $role): array => [$role->value => $role->getLabel()])
            ->all();
    }
}
