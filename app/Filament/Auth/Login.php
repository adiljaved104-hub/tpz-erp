<?php

namespace App\Filament\Auth;

use App\Services\LoginBrandingService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

class Login extends \Filament\Auth\Pages\Login
{
    protected function branding(): array
    {
        return app(LoginBrandingService::class)->presentation();
    }

    public function getTitle(): string|Htmlable
    {
        return $this->branding()['title'];
    }

    public function getHeading(): string|Htmlable|null
    {
        return filled($this->userUndertakingMultiFactorAuthentication)
            ? 'Verify your sign-in'
            : null;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return filled($this->userUndertakingMultiFactorAuthentication)
            ? 'Enter the 6-digit code sent to your company email.'
            : null;
    }

    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()
            ->label('Email')
            ->placeholder('Company email');
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label('Password')
            ->hint(new HtmlString(Blade::render('<x-filament::link :href="route(\'auth.password.request\')" tabindex="-1">Forgot Password?</x-filament::link>')))
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->autocomplete('current-password')
            ->required();
    }

    protected function getAuthenticateFormAction(): Action
    {
        return parent::getAuthenticateFormAction()->label('Sign In');
    }
}
