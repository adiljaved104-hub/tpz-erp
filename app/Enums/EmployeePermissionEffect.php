<?php

namespace App\Enums;

enum EmployeePermissionEffect: string
{
    case Allow = 'allow';
    case Deny = 'deny';
}
