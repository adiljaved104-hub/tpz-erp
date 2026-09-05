<?php

namespace App\Enums;

enum PeoplePermission: string
{
    case EmployeeView = 'employee.view';
    case EmployeeCreate = 'employee.create';
    case EmployeeUpdate = 'employee.update';
    case EmployeeChangeRole = 'employee.change_role';
    case EmployeeChangeStatus = 'employee.change_status';
    case EmployeeLinkUser = 'employee.link_user';
    case EmployeeUnlinkUser = 'employee.unlink_user';
    case TeamView = 'team.view';
    case TeamManage = 'team.manage';
    case ActivityLogView = 'activity_log.view';
}
