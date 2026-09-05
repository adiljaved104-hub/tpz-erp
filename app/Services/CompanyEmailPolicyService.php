<?php

namespace App\Services;

use App\Enums\EmployeeRole;
use App\Models\LoginSecuritySetting;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CompanyEmailPolicyService
{
    public const DEFAULT_DOMAIN = 'techpointzone.com';

    public function allowedDomain(): string
    {
        if (! Schema::hasTable('login_security_settings') || ! Schema::hasColumn('login_security_settings', 'allowed_login_email_domain')) {
            return self::DEFAULT_DOMAIN;
        }

        return $this->normalizeDomain(LoginSecuritySetting::query()->find(1)?->allowed_login_email_domain ?: self::DEFAULT_DOMAIN);
    }

    public function normalizeDomain(string $domain): string
    {
        $domain = mb_strtolower(ltrim(trim($domain), '@'));

        if (function_exists('idn_to_ascii')) {
            $domain = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain;
        }

        return rtrim($domain, '.');
    }

    public function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function isAllowedEmail(string $email): bool
    {
        $email = $this->normalizeEmail($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $domain = substr($email, (int) strrpos($email, '@') + 1);

        return hash_equals($this->allowedDomain(), $this->normalizeDomain($domain));
    }

    public function validateAllowedEmail(string $email): string
    {
        $email = $this->normalizeEmail($email);
        if (! $this->isAllowedEmail($email)) {
            throw ValidationException::withMessages([
                'newEmail' => 'Use an approved @'.$this->allowedDomain().' company email address.',
            ]);
        }

        return $email;
    }

    public function allowsAuthentication(User $user): bool
    {
        $user->loadMissing('employee');

        return $user->employee?->status === true
            && $user->employee->user_id === $user->id
            && ($this->isAllowedEmail((string) $user->email) || $this->legacyOwnerTransitionAllowed($user));
    }

    public function legacyOwnerTransitionAllowed(User $user): bool
    {
        if ((int) $user->id !== EmployeeAccessService::INITIAL_OWNER_USER_ID || $user->employee?->role !== EmployeeRole::Owner) {
            return false;
        }

        if (! Schema::hasTable('login_security_settings') || ! Schema::hasColumn('login_security_settings', 'legacy_owner_email_transition_completed_at')) {
            return true;
        }

        return LoginSecuritySetting::query()->find(1)?->legacy_owner_email_transition_completed_at === null;
    }

    public function statusFor(User $user): string
    {
        return $this->isAllowedEmail((string) $user->email) ? 'Approved' : 'Update Required';
    }

    public function activeAccountsNeedingMigrationCount(): int
    {
        return User::query()
            ->whereHas('employee', fn ($query) => $query->where('status', true))
            ->get(['id', 'email'])
            ->reject(fn (User $user): bool => $this->isAllowedEmail((string) $user->email))
            ->count();
    }
}
