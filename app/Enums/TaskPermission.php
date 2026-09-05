<?php

namespace App\Enums;

enum TaskPermission: string
{
    case View = 'task.view';
    case Create = 'task.create';
    case Update = 'task.update';
    case Assign = 'task.assign';
    case ChangeStatus = 'task.change_status';
    case Complete = 'task.complete';
    case Cancel = 'task.cancel';
    case ViewTeam = 'task.view_team';
    case ManageAll = 'task.manage_all';
}
