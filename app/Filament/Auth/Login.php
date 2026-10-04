<?php

namespace App\Filament\Auth;

use App\Models\User;
use App\Services\LoginBrandingService;
use App\Services\Security\LoginAttemptService;
use App\Services\Security\PasswordAgeService;
use App\Services\Security\WebInactivityService;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Filament\Auth\MultiFactor\Contracts\HasBeforeChallengeHook;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;

class Login extends \Filament\Auth\Pages\Login
{
    public function authenticate(): ?LoginResponseContract
    {
        $data = $this->form->getState();
        $identifier = (string) ($data['email'] ?? '');
        $ipAddress = request()->ip();
        $attempts = app(LoginAttemptService::class);

        if ($attempts->isLocked($identifier, $ipAddress)) {
            throw ValidationException::withMessages([
                'data.email' => 'Too many sign-in attempts. Try again later.',
            ]);
        }

        /** @var SessionGuard $authGuard */
        $authGuard = Filament::auth();
        $authProvider = $authGuard->getProvider();
        $credentials = $this->getCredentialsFromFormData($data);
        $timeboxDuration = (int) config('auth.timebox_duration', 200_000);

        $user = app(Timebox::class)->call(function (Timebox $timebox) use ($authProvider, $authGuard, $credentials, $attempts, $identifier, $ipAddress): Authenticatable {
            $this->fireAttemptingEvent($authGuard, $credentials, false);
            $user = $authProvider->retrieveByCredentials($credentials);

            if ((! $user) || (! $authProvider->validateCredentials($user, $credentials)) || (! $this->isUserAllowedToAccessPanel($user))) {
                $this->userUndertakingMultiFactorAuthentication = null;
                $attempts->recordFailure($identifier, $ipAddress, $user instanceof User ? $user : null, 'web');
                $this->fireFailedEvent($authGuard, $user, $credentials);
                $this->throwFailureValidationException();
            }

            if ($user instanceof User && app(PasswordAgeService::class)->isExpired($user)) {
                app(PasswordAgeService::class)->auditRotationRequired($user, 'web');
                throw ValidationException::withMessages([
                    'data.email' => 'Your password has expired. Use Forgot Password to set a new password.',
                ]);
            }

            $timebox->returnEarly();

            return $user;
        }, $timeboxDuration);

        $needsMultiFactorChallenge = app(Timebox::class)->call(function (Timebox $timebox) use ($user): bool {
            if ($user instanceof User && $this->consumePasswordlessMfaProof($user)) {
                return false;
            }

            if (filled($this->userUndertakingMultiFactorAuthentication) && decrypt($this->userUndertakingMultiFactorAuthentication) === $user->getAuthIdentifier()) {
                if ($this->isMultiFactorChallengeRateLimited($user)) {
                    return true;
                }

                $this->multiFactorChallengeForm->validate();

                return false;
            }

            foreach (Filament::getMultiFactorAuthenticationProviders() as $provider) {
                if (! $provider->isEnabled($user)) {
                    continue;
                }

                $this->userUndertakingMultiFactorAuthentication = encrypt($user->getAuthIdentifier());
                if ($provider instanceof HasBeforeChallengeHook) {
                    $provider->beforeChallenge($user);
                }
                break;
            }

            if (filled($this->userUndertakingMultiFactorAuthentication)) {
                $this->multiFactorChallengeForm->fill();

                return true;
            }

            return false;
        }, $timeboxDuration);

        if ($needsMultiFactorChallenge) {
            return null;
        }

        if (! $authGuard->attemptWhen($credentials, fn (Authenticatable $candidate): bool => $this->isUserAllowedToAccessPanel($candidate), false)) {
            $attempts->recordFailure($identifier, $ipAddress, $user instanceof User ? $user : null, 'web');
            $this->fireFailedEvent($authGuard, $user, $credentials);
            $this->throwFailureValidationException();
        }

        $attempts->clear($identifier, $ipAddress);
        session()->regenerate();
        session()->put(WebInactivityService::SESSION_KEY, now()->timestamp);

        return app(LoginResponseContract::class);
    }

    private function consumePasswordlessMfaProof(User $user): bool
    {
        $proof = session()->get('auth_security.passwordless_mfa_proof');

        if (
            is_array($proof)
            && ((int) ($proof['user_id'] ?? 0) === $user->id)
            && ((int) ($proof['expires_at'] ?? 0) >= now()->timestamp)
        ) {
            session()->forget('auth_security.passwordless_mfa_proof');

            return true;
        }

        session()->forget('auth_security.passwordless_mfa_proof');

        return false;
    }

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

    protected function getRememberFormComponent(): Component
    {
        return Hidden::make('remember')->default(false);
    }

    protected function getAuthenticateFormAction(): Action
    {
        return parent::getAuthenticateFormAction()->label('Sign In');
    }
}
