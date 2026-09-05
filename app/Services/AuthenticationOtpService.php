<?php

namespace App\Services;

use App\Enums\AuthenticationOtpPurpose;
use App\Exceptions\OtpChallengeException;
use App\Exceptions\OtpCooldownException;
use App\Models\AuthenticationOtpChallenge;
use App\Models\User;
use App\Notifications\AuthenticationOtpNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class AuthenticationOtpService
{
    public const EXPIRY_MINUTES = 5;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_COOLDOWN_SECONDS = 60;

    public function eligibleUserForCompanyEmail(string $email): ?User
    {
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])
            ->whereHas('employee', fn ($query) => $query->where('status', true))
            ->with('employee')
            ->first();

        return $user && $this->isEligible($user) ? $user : null;
    }

    public function isEligible(User $user): bool
    {
        $user->loadMissing('employee');

        return app(CompanyEmailPolicyService::class)->allowsAuthentication($user)
            && filter_var($user->email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function issue(User $user, AuthenticationOtpPurpose $purpose, ?string $ipAddress = null): AuthenticationOtpChallenge
    {
        return $this->issueToRecipient($user, $purpose, null, $ipAddress);
    }

    public function issueToNewCompanyEmail(User $user, string $recipientEmail, ?string $ipAddress = null): AuthenticationOtpChallenge
    {
        $recipientEmail = app(CompanyEmailPolicyService::class)->validateAllowedEmail($recipientEmail);

        return $this->issueToRecipient($user, AuthenticationOtpPurpose::EmailChangeNew, $recipientEmail, $ipAddress);
    }

    public function issueToRecoveryEmail(User $user, string $recipientEmail, ?string $ipAddress = null): AuthenticationOtpChallenge
    {
        $recipientEmail = app(CompanyEmailPolicyService::class)->validateAllowedEmail($recipientEmail);

        return $this->issueToRecipient($user, AuthenticationOtpPurpose::EmailRecoveryNew, $recipientEmail, $ipAddress);
    }

    private function issueToRecipient(User $user, AuthenticationOtpPurpose $purpose, ?string $recipientEmail, ?string $ipAddress): AuthenticationOtpChallenge
    {
        if (! $this->isEligibleForPurpose($user, $purpose)) {
            throw new OtpChallengeException('This account is not eligible for email verification.');
        }

        $rateKey = $this->requestRateKey($user, $purpose, $ipAddress);
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            throw new OtpChallengeException('Too many verification-code requests. Please try again later.');
        }

        RateLimiter::hit($rateKey, 600);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $challenge = DB::transaction(function () use ($user, $purpose, $ipAddress, $code): AuthenticationOtpChallenge {
            $latest = AuthenticationOtpChallenge::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose->value)
                ->latest('created_at')
                ->lockForUpdate()
                ->first();

            if ($latest && $latest->created_at->greaterThan(now()->subSeconds(self::RESEND_COOLDOWN_SECONDS))) {
                throw new OtpCooldownException((int) max(1, self::RESEND_COOLDOWN_SECONDS - $latest->created_at->diffInSeconds(now())));
            }

            AuthenticationOtpChallenge::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose->value)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            return AuthenticationOtpChallenge::query()->create([
                'user_id' => $user->id,
                'purpose' => $purpose,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
                'attempts' => 0,
                'requested_ip_hash' => filled($ipAddress) ? hash_hmac('sha256', $ipAddress, (string) config('app.key')) : null,
            ]);
        });

        try {
            $notification = new AuthenticationOtpNotification($code, $purpose, self::EXPIRY_MINUTES, $challenge->id);
            if ($recipientEmail === null) {
                $user->notifyNow($notification);
            } else {
                Notification::route('mail', $recipientEmail)->notifyNow($notification);
            }
        } catch (Throwable $exception) {
            $challenge->forceFill(['consumed_at' => now()])->save();
            Log::warning('Authentication verification email could not be queued.', [
                'exception_class' => $exception::class,
                'purpose' => $purpose->value,
            ]);

            throw new OtpChallengeException('Unable to send the verification code. Please try again.', previous: $exception);
        }

        return $challenge;
    }

    public function verify(string $challengeId, AuthenticationOtpPurpose $purpose, #[\SensitiveParameter] string $code): ?User
    {
        return DB::transaction(function () use ($challengeId, $purpose, $code): ?User {
            $challenge = AuthenticationOtpChallenge::query()->with('user.employee')->lockForUpdate()->find($challengeId);

            if (! $challenge || $challenge->purpose !== $purpose || $challenge->consumed_at || $challenge->expires_at->isPast() || $challenge->attempts >= self::MAX_ATTEMPTS) {
                return null;
            }

            $challenge->attempts++;
            $valid = Hash::check($code, $challenge->code_hash) && $this->isEligibleForPurpose($challenge->user, $purpose);

            if ($valid || $challenge->attempts >= self::MAX_ATTEMPTS) {
                $challenge->consumed_at = now();
            }
            $challenge->save();

            return $valid ? $challenge->user : null;
        });
    }

    public function remainingCooldown(User $user, AuthenticationOtpPurpose $purpose): int
    {
        $latest = AuthenticationOtpChallenge::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose->value)
            ->latest('created_at')
            ->first();

        if (! $latest) {
            return 0;
        }

        return (int) ceil(max(0, self::RESEND_COOLDOWN_SECONDS - $latest->created_at->diffInSeconds(now())));
    }

    private function requestRateKey(User $user, AuthenticationOtpPurpose $purpose, ?string $ipAddress): string
    {
        return 'auth-otp-request:'.$purpose->value.':'.$user->id.':'.hash('sha256', (string) $ipAddress);
    }

    private function isEligibleForPurpose(User $user, AuthenticationOtpPurpose $purpose): bool
    {
        $user->loadMissing('employee');

        if (in_array($purpose, [AuthenticationOtpPurpose::EmailChangeCurrent, AuthenticationOtpPurpose::EmailChangeNew, AuthenticationOtpPurpose::EmailRecoveryNew], true)) {
            return $user->employee?->status === true
                && $user->employee->user_id === $user->id
                && filter_var($user->email, FILTER_VALIDATE_EMAIL) !== false;
        }

        return $this->isEligible($user);
    }
}
