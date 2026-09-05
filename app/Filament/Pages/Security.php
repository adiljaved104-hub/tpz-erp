<?php

namespace App\Filament\Pages;

use App\Enums\AuthenticationOtpPurpose;
use App\Exceptions\OtpChallengeException;
use App\Models\User;
use App\Services\AuthenticationOtpService;
use App\Services\TwoFactorService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Hash;

class Security extends Page
{
    protected string $view = 'filament.pages.security';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static string|\UnitEnum|null $navigationGroup = 'Account';

    protected static ?string $title = 'Security';

    protected static ?int $navigationSort = 100;

    public bool $showEnableChallenge = false;

    public string $verificationCode = '';

    public string $currentPassword = '';

    public int $resendSeconds = 0;

    public int $cooldownVersion = 0;

    public function requestEnable(AuthenticationOtpService $challenges): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $challenges->isEligible($user), 403);

        try {
            $challenge = $challenges->issue($user, AuthenticationOtpPurpose::TwoFactor, request()->ip());
            session()->put('auth_security.enable_two_factor_challenge', $challenge->id);
            $this->showEnableChallenge = true;
            $this->resendSeconds = $challenges->remainingCooldown($user, AuthenticationOtpPurpose::TwoFactor);
            $this->cooldownVersion++;
            Notification::make()->success()->title('Verification code sent')->send();
        } catch (OtpChallengeException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
        }
    }

    public function resendEnable(AuthenticationOtpService $challenges): void
    {
        $this->requestEnable($challenges);
    }

    public function confirmEnable(AuthenticationOtpService $challenges, TwoFactorService $twoFactor): void
    {
        $this->validate(['verificationCode' => ['required', 'digits:6']]);
        $user = $challenges->verify((string) session('auth_security.enable_two_factor_challenge'), AuthenticationOtpPurpose::TwoFactor, $this->verificationCode);
        if (! $user || $user->id !== auth()->id()) {
            $this->addError('verificationCode', 'The verification code is invalid or has expired.');

            return;
        }

        $twoFactor->enableSelf($user);
        session()->forget('auth_security.enable_two_factor_challenge');
        $this->reset('verificationCode', 'showEnableChallenge');
        Notification::make()->success()->title('Email two-factor authentication enabled')->send();
    }

    public function disable(TwoFactorService $twoFactor): void
    {
        $this->validate(['currentPassword' => ['required', 'current_password']]);
        $user = auth()->user();
        abort_unless($user instanceof User && Hash::check($this->currentPassword, $user->password), 403);
        $twoFactor->disableSelf($user);
        $this->reset('currentPassword');
        Notification::make()->success()->title('Email two-factor authentication disabled')->send();
    }
}
