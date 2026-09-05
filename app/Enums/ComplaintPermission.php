<?php

namespace App\Enums;

enum ComplaintPermission: string
{
    case View = 'complaint.view';
    case Create = 'complaint.create';
    case Update = 'complaint.update';
    case Assign = 'complaint.assign';
    case Resolve = 'complaint.resolve';
}
