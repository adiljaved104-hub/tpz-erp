<?php

namespace App\Enums;

enum ResponsibilityPermission: string
{
    case ViewAll = 'responsibility.view_all';
    case ViewTeam = 'responsibility.view_team';
    case ViewOwn = 'responsibility.view_own';
    case Assign = 'responsibility.assign';
    case Reassign = 'responsibility.reassign';
    case Deactivate = 'responsibility.deactivate';
    case ViewHistory = 'responsibility.view_history';
    case ManagePlatforms = 'responsibility.manage_platforms';
}
