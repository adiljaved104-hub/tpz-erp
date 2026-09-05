<?php

namespace App\Services;

use App\Enums\AuthenticationOtpPurpose;
use App\Enums\AuthSecurityPermission;
use App\Enums\EmployeeRole;
use App\Models\AuthenticationOtpChallenge;
use App\Models\LoginEmailChangeRequest;
use App\Models\LoginSecuritySetting;
use App\Models\User;
use App\Notifications\LoginEmailChangedNotification;
use App\Services\Authorization\AuthSecurityAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class LoginEmailChangeService
{
    public function __construct(
        private readonly AuthenticationOtpService $otp,
        private readonly CompanyEmailPolicyService $policy,
        private readonly AuthSecurityAuthorization $authorization,
        private readonly ActivityLogger $activity,
    ) {}

    public function authorize(User $actor, User $target): void
    {
        $actor->loadMissing('employee');
        $target->loadMissing('employee');

        if ($target->employee?->status !== true || $target->employee->user_id !== $target->id) {
            throw new AuthorizationException;
        }

        if ($actor->is($target)) {
            return;
        }

        if (! in_array($actor->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)) {
            throw new AuthorizationException;
        }

        $this->authorization->authorize($actor, AuthSecurityPermission::Manage);

        if ($target->employee->role === EmployeeRole::Owner && $actor->employee?->role !== EmployeeRole::Owner) {
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

    public function start(User $actor, User $target, ?string $ipAddress): LoginEmailChangeRequest
    {
        $this->authorize($actor, $target);

        $request = DB::transaction(function () use ($actor, $target): LoginEmailChangeRequest {
            $lockedTarget = User::query()->lockForUpdate()->findOrFail($target->id);
            LoginEmailChangeRequest::query()
                ->where('user_id', $lockedTarget->id)
                ->whereNull('completed_at')
                ->whereNull('cancelled_at')
                ->update(['cancelled_at' => now()]);

            return LoginEmailChangeRequest::query()->create([
                'user_id' => $lockedTarget->id,
                'initiated_by_user_id' => $actor->id,
                'current_email' => $this->policy->normalizeEmail((string) $lockedTarget->email),
                'expires_at' => now()->addMinutes(30),
            ]);
        });

        try {
            $challenge = $this->otp->issue($target, AuthenticationOtpPurpose::EmailChangeCurrent, $ipAddress);
            $request->forceFill(['current_otp_challenge_id' => $challenge->id])->save();
        } catch (Throwable $exception) {
            $request->forceFill(['cancelled_at' => now()])->save();
            throw $exception;
        }

        $this->activity->log('auth_security.login_email_change_started', $actor, $target, [
            'request_id' => $request->id,
            'target_user_id' => $target->id,
            'current_email_domain' => $this->domainOf($request->current_email),
        ]);
        if (! $actor->is($target)) {
            $this->activity->log('auth_security.management_login_email_change_started', $actor, $target, [
                'request_id' => $request->id,
                'target_user_id' => $target->id,
            ]);
        }

        return $request->refresh();
    }

    public function verifyCurrent(LoginEmailChangeRequest $request, string $code, User $actor): LoginEmailChangeRequest
    {
        $this->authorizeRequest($request, $actor);
        if ($request->current_verified_at !== null) {
            return $request;
        }

        $this->assertChallengeReady(
            $request->current_otp_challenge_id,
            AuthenticationOtpPurpose::EmailChangeCurrent,
            'currentCode',
        );
        $user = $this->otp->verify((string) $request->current_otp_challenge_id, AuthenticationOtpPurpose::EmailChangeCurrent, $code);
        if (! $user || (int) $user->id !== (int) $request->user_id) {
            $this->throwChallengeFailure(
                $request->current_otp_challenge_id,
                AuthenticationOtpPurpose::EmailChangeCurrent,
                'currentCode',
            );
        }

        $request->forceFill(['current_verified_at' => now()])->save();
        $this->activity->log('auth_security.current_login_email_verified', $actor, $user, ['request_id' => $request->id]);

        return $request->refresh();
    }

    public function resendCurrentEmailCode(LoginEmailChangeRequest $request, User $actor, ?string $ipAddress): LoginEmailChangeRequest
    {
        $this->authorizeRequest($request, $actor);
        if ($request->current_verified_at !== null) {
            throw ValidationException::withMessages(['currentCode' => 'The current login email is already verified.']);
        }

        $challenge = $this->otp->issue($request->user, AuthenticationOtpPurpose::EmailChangeCurrent, $ipAddress);
        $request->forceFill(['current_otp_challenge_id' => $challenge->id])->save();

        return $request->refresh();
    }

    public function sendNewEmailCode(LoginEmailChangeRequest $request, string $newEmail, User $actor, ?string $ipAddress): LoginEmailChangeRequest
    {
        $this->authorizeRequest($request, $actor);
        if ($request->current_verified_at === null) {
            throw ValidationException::withMessages(['newEmail' => 'Verify the current login email first.']);
        }

        $newEmail = $this->policy->validateAllowedEmail($newEmail);
        $this->assertUniqueEmail($newEmail, $request->user_id);
        if (LoginEmailChangeRequest::query()
            ->where('id', '!=', $request->id)
            ->whereRaw('LOWER(new_email) = ?', [$newEmail])
            ->whereNull('completed_at')
            ->whereNull('cancelled_at')
            ->where('expires_at', '>', now())
            ->exists()) {
            throw ValidationException::withMessages(['newEmail' => 'This login email is already being verified for another account.']);
        }
        if (hash_equals($this->policy->normalizeEmail($request->current_email), $newEmail)) {
            throw ValidationException::withMessages(['newEmail' => 'The new login email must be different from the current login email.']);
        }

        $request->forceFill(['new_email' => $newEmail, 'new_otp_challenge_id' => null])->save();
        $challenge = $this->otp->issueToNewCompanyEmail($request->user, $newEmail, $ipAddress);
        $request->forceFill(['new_otp_challenge_id' => $challenge->id])->save();

        $this->activity->log('auth_security.new_login_email_code_sent', $actor, $request->user, [
            'request_id' => $request->id,
            'new_email_domain' => $this->domainOf($newEmail),
        ]);

        return $request->refresh();
    }

    public function resendNewEmailCode(LoginEmailChangeRequest $request, User $actor, ?string $ipAddress): LoginEmailChangeRequest
    {
        $this->authorizeRequest($request, $actor);
        if ($request->current_verified_at === null || blank($request->new_email)) {
            throw ValidationException::withMessages(['newCode' => 'Verify the current email and enter the new company email first.']);
        }

        $challenge = $this->otp->issueToNewCompanyEmail($request->user, $request->new_email, $ipAddress);
        $request->forceFill(['new_otp_challenge_id' => $challenge->id])->save();

        return $request->refresh();
    }

    public function complete(LoginEmailChangeRequest $request, string $code, User $actor, ?string $currentSessionId): User
    {
        $this->authorizeRequest($request, $actor);
        $this->assertChallengeReady(
            $request->new_otp_challenge_id,
            AuthenticationOtpPurpose::EmailChangeNew,
            'newCode',
        );
        $verified = $this->otp->verify((string) $request->new_otp_challenge_id, AuthenticationOtpPurpose::EmailChangeNew, $code);
        if (! $verified || (int) $verified->id !== (int) $request->user_id) {
            $this->throwChallengeFailure(
                $request->new_otp_challenge_id,
                AuthenticationOtpPurpose::EmailChangeNew,
                'newCode',
            );
        }

        [$target, $oldEmail, $newEmail] = DB::transaction(function () use ($request, $actor, $currentSessionId): array {
            $lockedRequest = LoginEmailChangeRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($lockedRequest->completed_at !== null) {
                return [$lockedRequest->user, $lockedRequest->current_email, $lockedRequest->new_email];
            }
            $this->assertUsable($lockedRequest);
            if ($lockedRequest->current_verified_at === null || blank($lockedRequest->new_email)) {
                throw ValidationException::withMessages(['newCode' => 'Complete both email verification steps first.']);
            }

            $target = User::query()->with('employee')->lockForUpdate()->findOrFail($lockedRequest->user_id);
            $this->authorize($actor, $target);
            if (! hash_equals($this->policy->normalizeEmail($lockedRequest->current_email), $this->policy->normalizeEmail((string) $target->email))) {
                throw ValidationException::withMessages(['newCode' => 'The login email changed during verification. Start again.']);
            }
            $newEmail = $this->policy->validateAllowedEmail($lockedRequest->new_email);
            $this->assertUniqueEmail($newEmail, $target->id);
            $oldEmail = (string) $target->email;

            $target->forceFill(['email' => $newEmail, 'remember_token' => Str::random(60)])->save();
            $lockedRequest->forceFill(['new_verified_at' => now(), 'completed_at' => now()])->save();

            $this->activity->log('auth_security.new_login_email_verified', $actor, $target, [
                'request_id' => $lockedRequest->id,
                'new_email_domain' => $this->domainOf($newEmail),
            ]);

            if ((int) $target->id === EmployeeAccessService::INITIAL_OWNER_USER_ID && $target->employee?->role === EmployeeRole::Owner) {
                $settings = LoginSecuritySetting::query()->lockForUpdate()->find(1) ?? (new LoginSecuritySetting)->forceFill(['id' => 1]);
                $settings->legacy_owner_email_transition_completed_at = now();
                $settings->save();
            }

            $this->invalidateTargetSessions($target, $actor->is($target) ? $currentSessionId : null);
            $this->activity->log('auth_security.login_email_changed', $actor, $target, [
                'request_id' => $lockedRequest->id,
                'target_user_id' => $target->id,
                'old_email_domain' => $this->domainOf($oldEmail),
                'new_email_domain' => $this->domainOf($newEmail),
            ]);

            return [$target->refresh(), $oldEmail, $newEmail];
        });

        $this->sendConfirmation($oldEmail, true);
        $this->sendConfirmation($newEmail, false);

        return $target;
    }

    private function authorizeRequest(LoginEmailChangeRequest $request, User $actor): void
    {
        $request->loadMissing('user.employee');
        $this->authorize($actor, $request->user);
        $this->assertUsable($request);
    }

    private function assertUsable(LoginEmailChangeRequest $request): void
    {
        if ($request->completed_at !== null || $request->cancelled_at !== null || $request->expires_at->isPast()) {
            throw ValidationException::withMessages(['currentCode' => 'This email-change request is no longer active. Start again.']);
        }
    }

    private function assertUniqueEmail(string $email, int $exceptUserId): void
    {
        if (User::query()->where('id', '!=', $exceptUserId)->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['newEmail' => 'This login email is already in use.']);
        }
    }

    private function assertChallengeReady(?string $challengeId, AuthenticationOtpPurpose $purpose, string $field): AuthenticationOtpChallenge
    {
        $challenge = filled($challengeId) ? AuthenticationOtpChallenge::query()->find($challengeId) : null;

        if (! $challenge || $challenge->purpose !== $purpose) {
            throw ValidationException::withMessages([$field => 'The verification code is no longer valid. Please request a new code.']);
        }
        if ($challenge->expires_at->isPast()) {
            throw ValidationException::withMessages([$field => 'Verification code has expired. Please request a new code.']);
        }
        if ($challenge->attempts >= AuthenticationOtpService::MAX_ATTEMPTS) {
            throw ValidationException::withMessages([$field => 'Too many incorrect attempts. Please request a new code.']);
        }
        if ($challenge->consumed_at !== null) {
            throw ValidationException::withMessages([$field => 'The verification code is no longer valid. Please request a new code.']);
        }

        return $challenge;
    }

    private function throwChallengeFailure(?string $challengeId, AuthenticationOtpPurpose $purpose, string $field): never
    {
        $challenge = filled($challengeId) ? AuthenticationOtpChallenge::query()->find($challengeId) : null;

        if ($challenge?->expires_at?->isPast()) {
            throw ValidationException::withMessages([$field => 'Verification code has expired. Please request a new code.']);
        }
        if ($challenge?->attempts >= AuthenticationOtpService::MAX_ATTEMPTS) {
            throw ValidationException::withMessages([$field => 'Too many incorrect attempts. Please request a new code.']);
        }
        if (! $challenge || $challenge->purpose !== $purpose) {
            throw ValidationException::withMessages([$field => 'The verification code is no longer valid. Please request a new code.']);
        }

        throw ValidationException::withMessages([$field => 'The verification code is incorrect.']);
    }

    private function invalidateTargetSessions(User $target, ?string $exceptId): void
    {
        if (config('session.driver') !== 'database' || ! Schema::hasTable((string) config('session.table', 'sessions'))) {
            return;
        }

        DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $target->id)
            ->when(filled($exceptId), fn ($query) => $query->where('id', '!=', $exceptId))
            ->delete();
    }

    private function sendConfirmation(string $email, bool $previous): void
    {
        try {
            Notification::route('mail', $email)->notify(new LoginEmailChangedNotification($previous));
        } catch (Throwable $exception) {
            Log::warning('Login email change confirmation could not be queued.', ['exception_class' => $exception::class]);
        }
    }

    private function domainOf(string $email): string
    {
        return mb_strtolower((string) substr(strrchr($email, '@') ?: '', 1));
    }
}
