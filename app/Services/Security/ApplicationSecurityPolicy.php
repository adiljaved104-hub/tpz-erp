<?php

namespace App\Services\Security;

use Illuminate\Validation\Rules\Password;

final class ApplicationSecurityPolicy
{
    public const int PASSWORD_MIN_LENGTH = 12;

    public const int PASSWORD_MAX_AGE_DAYS = 365;

    public const int LOGIN_MAX_ATTEMPTS = 10;

    public const int LOGIN_LOCKOUT_MINUTES = 30;

    public const int WEB_INACTIVITY_MINUTES = 15;

    public static function passwordRule(): Password
    {
        return Password::min(self::PASSWORD_MIN_LENGTH)
            ->letters()
            ->mixedCase()
            ->numbers()
            ->symbols();
    }
}
