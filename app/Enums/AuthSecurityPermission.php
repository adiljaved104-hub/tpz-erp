<?php

namespace App\Enums;

enum AuthSecurityPermission: string
{
    case View = 'auth_security.view';
    case Manage = 'auth_security.manage';
    case ManageTwoFactor = 'auth_security.manage_2fa';
}
