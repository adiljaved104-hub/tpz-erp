<?php

namespace App\Enums;

enum PerformancePermission: string
{
    case ViewOwn = 'performance.view_own';
    case ViewTeam = 'performance.view_team';
    case ViewAll = 'performance.view_all';
}
