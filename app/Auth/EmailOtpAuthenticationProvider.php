<?php

namespace App\Auth;

use App\Enums\AuthenticationOtpPurpose;
use App\Exceptions\OtpChallengeException;
use App\Models\User;
use App\Services\AuthenticationOtpService;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\Contracts\HasBeforeChallengeHook;
use Filament\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;
use Livewire\Component as LivewireComponent;

class EmailOtpAuthenticationProvider implements HasBeforeChallengeHook, MultiFactorAuthenticationProvider
{
    private const SESSION_KEY = 'auth_security.two_factor_challenge';

    public function __construct(private readonly AuthenticationOtpService $challenges) {}

    public function getId(): string
    {
        return 'company_email_otp';
    }

    public function getLoginFormLabel(): string
    {
        return 'Email verification code';
    }

    public function isEnabled(Authenticatable $user): bool
    {
        return $user instanceof User && filled($user->email_two_factor_enabled_at);
    }

    public function beforeChallenge(Authenticatable $user): void
    {
        if (! $user instanceof User) {
            throw ValidationException::withMessages(['data.email' => 'Unable to start verification.']);
        }

        try {
            $challenge = $this->challenges->issue($user, AuthenticationOtpPurpose::TwoFactor, request()->ip());
            session()->put(self::SESSION_KEY, $challenge->id);
        } catch (OtpChallengeException $exception) {
            throw ValidationException::withMessages(['data.email' => $exception->getMessage()]);
        }
    }

    /** @return array<Component|Action> */
    public function getManagementSchemaComponents(): array
    {
        return [];
    }

    /** @return array<Component|Action> */
    public function getChallengeFormComponents(Authenticatable $user): array
    {
        $remaining = $user instanceof User
            ? $this->challenges->remainingCooldown($user, AuthenticationOtpPurpose::TwoFactor)
            : 0;

        return [
            OneTimeCodeInput::make('code')
                ->label('Verification Code')
                ->extraFieldWrapperAttributes([
                    'x-data' => "{ remaining: {$remaining}, timer: null, start() { clearInterval(this.timer); if (this.remaining <= 0) return; this.timer = setInterval(() => { this.remaining = Math.max(0, this.remaining - 1); if (this.remaining === 0) clearInterval(this.timer); }, 1000); } }",
                    'x-init' => 'start()',
                    'x-on:otp-code-resent.window' => 'remaining = '.AuthenticationOtpService::RESEND_COOLDOWN_SECONDS.'; start()',
                ])
                ->belowContent([
                    Text::make('Enter the 6-digit code sent to your company email. It expires in '.AuthenticationOtpService::EXPIRY_MINUTES.' minutes.'),
                    Text::make("Resend code in {$remaining}s")
                        ->extraAttributes([
                            'x-show' => 'remaining > 0',
                            'x-text' => '`Resend code in ${remaining}s`',
                            'style' => $remaining === 0 ? 'display:none' : null,
                        ]),
                    Action::make('resendTwoFactorCode')
                        ->label('Resend Code')
                        ->link()
                        ->extraAttributes([
                            'x-show' => 'remaining === 0',
                            'style' => $remaining > 0 ? 'display:none' : null,
                        ])
                        ->action(function (LivewireComponent $livewire) use ($user): void {
                            try {
                                $this->beforeChallenge($user);
                                $livewire->dispatch('otp-code-resent');
                                Notification::make()->success()->title('A new verification code was sent.')->send();
                            } catch (ValidationException $exception) {
                                Notification::make()->danger()->title(collect($exception->errors())->flatten()->first() ?: 'Unable to resend the code.')->send();
                            }
                        }),
                ])
                ->required()
                ->rule(function (): \Closure {
                    return function (string $attribute, mixed $value, \Closure $fail): void {
                        $challengeId = session(self::SESSION_KEY);
                        if (is_string($challengeId) && is_string($value) && $this->challenges->verify($challengeId, AuthenticationOtpPurpose::TwoFactor, $value)) {
                            session()->forget(self::SESSION_KEY);

                            return;
                        }

                        $fail('The verification code is invalid or has expired.');
                    };
                }),
        ];
    }
}
