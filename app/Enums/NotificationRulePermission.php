<?php

namespace App\Enums;

enum NotificationRulePermission: string
{
    case View = 'notification_rules.view';
    case Manage = 'notification_rules.manage';
}
