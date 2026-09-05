<?php

namespace App\Enums;

enum CustomerReturnSource: string
{
    case Manual = 'manual';
    case Marketplace = 'marketplace';
    case Api = 'api';
}
