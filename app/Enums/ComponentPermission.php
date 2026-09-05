<?php

namespace App\Enums;

enum ComponentPermission: string
{
    case View = 'component.view';
    case Create = 'component.create';
    case Update = 'component.update';
    case ChangeStatus = 'component.change_status';
    case ViewRecoveryValue = 'component.view_recovery_value';
    case ApproveRecoveryValue = 'component.approve_recovery_value';
}
