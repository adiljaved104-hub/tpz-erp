<?php

namespace App\Services\Security;

use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Cache;

final class PasswordAgeService
{
    public function isExpired(User $user): bool
    {
        $changedAt = $user->password_changed_at ?? $user->created_at;

        return $changedAt !== null
            && $changedAt->lte(now()->subDays(ApplicationSecurityPolicy::PASSWORD_MAX_AGE_DAYS));
    }

    public function auditRotationRequired(User $user, string $channel): void
    {
        $key = sprintf('security:password-rotation-audit:%d:%s', $user->getKey(), now()->toDateString());

        if (! Cache::add($key, true, now()->endOfDay())) {
            return;
        }

        app(ActivityLogger::class)->log(
            'auth_security.password_rotation_required',
            $user,
            $user,
            ['channel' => $channel],
            'Password rotation required by security policy.',
        );
    }
}
