<?php

namespace App\Enums;

enum AuthenticationOtpPurpose: string
{
    case Login = 'login';
    case TwoFactor = 'two_factor';
    case PasswordReset = 'password_reset';
    case EmailChangeCurrent = 'email_change_current';
    case EmailChangeNew = 'email_change_new';
    case EmailRecoveryNew = 'email_recovery_new';
}
