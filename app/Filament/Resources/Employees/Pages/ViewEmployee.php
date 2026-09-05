<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Enums\AuthSecurityPermission;
use App\Filament\Pages\ChangeLoginEmail;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\User;
use App\Services\LoginEmailChangeService;
use App\Services\TwoFactorService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Schema;

class ViewEmployee extends ViewRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('changeLoginEmail')
                ->label('Change Login Email')
                ->icon('heroicon-o-envelope')
                ->url(fn (): string => ChangeLoginEmail::getUrl(['employee' => $this->getRecord()->id]))
                ->visible(fn (): bool => Schema::hasTable('login_email_change_requests') && $this->getRecord()->user !== null
                    && app(LoginEmailChangeService::class)->allows(auth()->user(), $this->getRecord()->user)),
            Action::make('access')
                ->label('Access / Permissions')
                ->icon('heroicon-o-key')
                ->url(fn (): string => EmployeeResource::getUrl('access', ['record' => $this->getRecord()]))
                ->visible(fn (): bool => auth()->user()?->can('managePermissions', $this->getRecord()) === true),
            Action::make('disableEmailTwoFactor')
                ->label('Disable Email 2FA')
                ->icon('heroicon-o-lock-open')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('This disables email two-factor authentication for this employee. It does not change their password.')
                ->visible(fn (): bool => auth()->user()?->can(AuthSecurityPermission::ManageTwoFactor->value) === true
                    && $this->getRecord()->user?->email_two_factor_enabled_at !== null)
                ->action(function (TwoFactorService $twoFactor): void {
                    $target = $this->getRecord()->user;
                    $actor = auth()->user();
                    abort_unless($target instanceof User && $actor instanceof User, 403);
                    $twoFactor->disableForManagement($target, $actor);
                    Notification::make()->success()->title('Employee email 2FA disabled')->send();
                    $this->getRecord()->refresh();
                }),
            EditAction::make(),
        ];
    }
}
