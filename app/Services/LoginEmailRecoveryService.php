<?php

namespace App\Services;

use App\Enums\AuthenticationOtpPurpose;
use App\Enums\EmployeeRole;
use App\Models\LoginEmailRecoveryRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginEmailRecoveryService
{
    public function __construct(
        private readonly AuthenticationOtpService $otp,
        private readonly CompanyEmailPolicyService $policy,
        private readonly ActivityLogger $activity,
    ) {}

    public function authorize(User $actor, User $target): void
    {
        $actor->loadMissing('employee');
        $target->loadMissing('employee');

        if ($actor->employee?->status !== true
            || $actor->employee->role !== EmployeeRole::Owner
            || $actor->is($target)
            || $target->employee?->status !== true
            || $target->employee->user_id !== $target->id
            || $target->employee->role === EmployeeRole::Owner
            || ! Schema::hasTable('login_email_recovery_requests')) {
            throw new AuthorizationException;
        }
    }

    public function allows(User $actor, User $target): bool
    {
        try {
            $this->authorize($actor, $target);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    public function start(User $actor, User $target, #[\SensitiveParameter] string $password, string $reason, ?string $ipAddress): LoginEmailRecoveryRequest
    {
        $this->authorize($actor, $target);
        if (! Hash::check($password, (string) $actor->password)) {
            throw ValidationException::withMessages(['ownerPassword' => 'The Owner password is incorrect.']);
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => 'Enter a recovery reason between 10 and 1,000 characters.']);
        }

        $request = DB::transaction(function () use ($actor, $target, $reason): LoginEmailRecoveryRequest {
            LoginEmailRecoveryRequest::query()
                ->where('user_id', $target->id)
                ->whereNull('completed_at')
                ->whereNull('cancelled_at')
                ->update(['cancelled_at' => now()]);

            return LoginEmailRecoveryRequest::query()->create([
                'user_id' => $target->id,
                'initiated_by_user_id' => $actor->id,
                'current_email' => $this->policy->normalizeEmail((string) $target->email),
                'reason' => $reason,
                'owner_password_verified_at' => now(),
                'owner_2fa_verified_at' => $actor->email_two_factor_enabled_at === null ? now() : null,
                'expires_at' => now()->addMinutes(30),
            ]);
        });

        if ($actor->email_two_factor_enabled_at !== null) {
            try {
                $challenge = $this->otp->issue($actor, AuthenticationOtpPurpose::TwoFactor, $ipAddress);
                $request->forceFill(['owner_2fa_challenge_id' => $challenge->id])->save();
            } catch (\Throwable $exception) {
                $request->forceFill(['cancelled_at' => now()])->save();
                throw $exception;
            }
        }

        $this->activity->log('auth_security.login_email_recovery_started', $actor, $target, [
            'request_id' => $request->id,
            'target_user_id' => $target->id,
            'recovery_status' => 'started',
        ]);

        return $request->refresh();
    }

    public function verifyOwnerTwoFactor(LoginEmailRecoveryRequest $request, string $code, User $actor): LoginEmailRecoveryRequest
    {
        $this->authorizeRequest($request, $actor);
        if ($request->owner_2fa_verified_at !== null) {
            return $request;
        }

        $verified = $this->otp->verify((string) $request->owner_2fa_challenge_id, AuthenticationOtpPurpose::TwoFactor, $code);
        if (! $verified || ! $verified->is($actor)) {
            throw ValidationException::withMessages(['ownerTwoFactorCode' => 'The Owner verification code is incorrect or expired.']);
        }

        $request->forceFill(['owner_2fa_verified_at' => now()])->save();

        return $request->refresh();
    }

    public function sendNewEmailCode(LoginEmailRecoveryRequest $request, string $newEmail, User $actor, ?string $ipAddress): LoginEmailRecoveryRequest
    {
        $this->authorizeRequest($request, $actor);
        if ($request->owner_2fa_verified_at === null) {
            throw ValidationException::withMessages(['newEmail' => 'Complete Owner re-authentication first.']);
        }

        $newEmail = $this->policy->validateAllowedEmail($newEmail);
        $this->assertUnique($newEmail, $request);
        if (hash_equals($this->policy->normalizeEmail($request->current_email), $newEmail)) {
            throw ValidationException::withMessages(['newEmail' => 'The recovery email must differ from the current login email.']);
        }

        $request->forceFill(['new_email' => $newEmail, 'new_otp_challenge_id' => null])->save();
        $challenge = $this->otp->issueToRecoveryEmail($request->user, $newEmail, $ipAddress);
        $request->forceFill(['new_otp_challenge_id' => $challenge->id])->save();

        return $request->refresh();
    }

    public function resendNewEmailCode(LoginEmailRecoveryRequest $request, User $actor, ?string $ipAddress): LoginEmailRecoveryRequest
    {
        $this->authorizeRequest($request, $actor);
        if ($request->owner_2fa_verified_at === null || blank($request->new_email)) {
            throw ValidationException::withMessages(['newCode' => 'Complete Owner re-authentication and enter the new company email first.']);
        }

        $challenge = $this->otp->issueToRecoveryEmail($request->user, $request->new_email, $ipAddress);
        $request->forceFill(['new_otp_challenge_id' => $challenge->id])->save();

        return $request->refresh();
    }

    public function complete(LoginEmailRecoveryRequest $request, string $code, User $actor): User
    {
        $this->authorizeRequest($request, $actor);
        $verified = $this->otp->verify((string) $request->new_otp_challenge_id, AuthenticationOtpPurpose::EmailRecoveryNew, $code);
        if (! $verified || (int) $verified->id !== (int) $request->user_id) {
            throw ValidationException::withMessages(['newCode' => 'The verification code is incorrect or expired.']);
        }

        return DB::transaction(function () use ($request, $actor): User {
            $locked = LoginEmailRecoveryRequest::query()->lockForUpdate()->findOrFail($request->id);
            $this->assertUsable($locked);
            if ($locked->owner_2fa_verified_at === null || blank($locked->new_email)) {
                throw ValidationException::withMessages(['newCode' => 'Complete Owner and new-email verification first.']);
            }

            $target = User::query()->with('employee')->lockForUpdate()->findOrFail($locked->user_id);
            $this->authorize($actor, $target);
            if (! hash_equals($this->policy->normalizeEmail($locked->current_email), $this->policy->normalizeEmail((string) $target->email))) {
                throw ValidationException::withMessages(['newCode' => 'The login email changed during recovery. Start again.']);
            }

            $newEmail = $this->policy->validateAllowedEmail((string) $locked->new_email);
            $this->assertUnique($newEmail, $locked);
            $target->forceFill(['email' => $newEmail, 'remember_token' => Str::random(60)])->save();
            $locked->forceFill(['new_verified_at' => now(), 'completed_at' => now()])->save();

            $this->activity->log('auth_security.login_email_recovery_completed', $actor, $target, [
                'request_id' => $locked->id,
                'target_user_id' => $target->id,
                'recovery_status' => 'completed',
            ]);

            return $target->refresh();
        });
    }

    private function authorizeRequest(LoginEmailRecoveryRequest $request, User $actor): void
    {
        $request->loadMissing('user.employee');
        $this->authorize($actor, $request->user);
        $this->assertUsable($request);
        if ((int) $request->initiated_by_user_id !== (int) $actor->id) {
            throw new AuthorizationException;
        }
    }

    private function assertUsable(LoginEmailRecoveryRequest $request): void
    {
        if ($request->completed_at !== null || $request->cancelled_at !== null || $request->expires_at->isPast()) {
            throw ValidationException::withMessages(['newCode' => 'This recovery request is no longer active.']);
        }
    }

    private function assertUnique(string $email, LoginEmailRecoveryRequest $request): void
    {
        if (User::query()->where('id', '!=', $request->user_id)->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['newEmail' => 'This login email is already in use.']);
        }
        if (LoginEmailRecoveryRequest::query()->where('id', '!=', $request->id)->whereRaw('LOWER(new_email) = ?', [$email])->whereNull('completed_at')->whereNull('cancelled_at')->where('expires_at', '>', now())->exists()) {
            throw ValidationException::withMessages(['newEmail' => 'This login email is already being verified for another account.']);
        }
    }
}
