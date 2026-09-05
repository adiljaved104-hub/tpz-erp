<?php

namespace App\Enums;

enum EmailSettingsPermission: string
{
    case View = 'email_settings.view';
    case Manage = 'email_settings.manage';
    case Test = 'email_settings.test';
}
