<?php

namespace App\Enums;

enum BackupSettingsPermission: string
{
    case View = 'backup_settings.view';
    case Manage = 'backup_settings.manage';
    case Run = 'backup_settings.run';
    case Verify = 'backup_settings.verify';
    case Download = 'backup_settings.download';
}
