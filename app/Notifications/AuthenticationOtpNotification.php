<?php

namespace App\Notifications;

use App\Enums\AuthenticationOtpPurpose;
use App\Models\AuthenticationOtpChallenge;
use App\Services\AuthenticationOtpService;
use App\Services\Branding\ApplicationBranding;
use App\Services\Notifications\EmailConfigurationService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use RuntimeException;
use SensitiveParameter;

class AuthenticationOtpNotification extends Notification
{
    public function __construct(
        #[SensitiveParameter] public readonly string $code,
        public readonly AuthenticationOtpPurpose $purpose,
        public readonly int $expiryMinutes = AuthenticationOtpService::EXPIRY_MINUTES,
        public readonly ?string $challengeId = null,
    ) {}

    public function via(object $notifiable): array
    {
        if (! isset($this->challengeId) || blank($this->challengeId)) {
            return [];
        }

        $challenge = AuthenticationOtpChallenge::query()->find($this->challengeId);
        if (! $challenge || $challenge->purpose !== $this->purpose || $challenge->consumed_at !== null || $challenge->expires_at->isPast()) {
            return [];
        }

        if (! app(EmailConfigurationService::class)->apply(false)) {
            throw new RuntimeException('Authentication email delivery is not configured.');
        }

        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $purpose = match ($this->purpose) {
            AuthenticationOtpPurpose::Login => 'Verification Code',
            AuthenticationOtpPurpose::TwoFactor => 'Two-Factor Verification',
            AuthenticationOtpPurpose::PasswordReset => 'Password Reset',
            AuthenticationOtpPurpose::EmailChangeCurrent => 'Login Email Verification',
            AuthenticationOtpPurpose::EmailChangeNew => 'New Login Email Verification',
            AuthenticationOtpPurpose::EmailRecoveryNew => 'Login Email Recovery Verification',
        };

        return app(ApplicationBranding::class)->mail(new MailMessage, $purpose)
            ->greeting('Verification code')
            ->line("Your verification code is: {$this->code}")
            ->line("This code expires in {$this->expiryMinutes} minutes.")
            ->line('If you did not request this, you can ignore this email.');
    }
}
