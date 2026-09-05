<?php

namespace App\Enums;

enum CompanyProfilePermission: string
{
    case View = 'company_profile.view';
    case Manage = 'company_profile.manage';
}
