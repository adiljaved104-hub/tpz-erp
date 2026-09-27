<?php

namespace App\Services\Security;

use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Str;

final class LoginAttemptService
{
    private const int IP_MAX_ATTEMPTS = 50;

    public function __construct(private readonly RateLimiter $limiter) {}

    public function isLocked(string $identifier, ?string $ipAddress): bool
    {
        return $this->limiter->tooManyAttempts($this->accountKey($identifier), ApplicationSecurityPolicy::LOGIN_MAX_ATTEMPTS)
            || $this->limiter->tooManyAttempts($this->ipKey($ipAddress), self::IP_MAX_ATTEMPTS);
    }

    public function recordFailure(string $identifier, ?string $ipAddress, ?User $user, string $channel): void
    {
        $decaySeconds = ApplicationSecurityPolicy::LOGIN_LOCKOUT_MINUTES * 60;
        $accountKey = $this->accountKey($identifier);
        $wasLocked = $this->limiter->tooManyAttempts($accountKey, ApplicationSecurityPolicy::LOGIN_MAX_ATTEMPTS);

        $this->limiter->hit($accountKey, $decaySeconds);
        $this->limiter->hit($this->ipKey($ipAddress), $decaySeconds);

        if (! $wasLocked && $this->limiter->tooManyAttempts($accountKey, ApplicationSecurityPolicy::LOGIN_MAX_ATTEMPTS)) {
            app(ActivityLogger::class)->log(
                'auth_security.login_locked',
                $user,
                $user,
                ['channel' => $channel],
                'Sign-in temporarily locked after repeated failed attempts.',
            );
        }
    }

    public function clear(string $identifier, ?string $ipAddress = null): void
    {
        $this->limiter->clear($this->accountKey($identifier));

        if ($ipAddress !== null) {
            $this->limiter->clear($this->ipKey($ipAddress));
        }
    }

    public function secondsRemaining(string $identifier, ?string $ipAddress): int
    {
        return max(
            $this->limiter->availableIn($this->accountKey($identifier)),
            $this->limiter->availableIn($this->ipKey($ipAddress)),
        );
    }

    private function accountKey(string $identifier): string
    {
        return 'auth-security:account:'.hash('sha256', Str::lower(trim($identifier)));
    }

    private function ipKey(?string $ipAddress): string
    {
        return 'auth-security:ip:'.hash('sha256', $ipAddress ?: 'unknown');
    }
}
