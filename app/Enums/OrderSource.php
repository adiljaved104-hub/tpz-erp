<?php

namespace App\Enums;

enum OrderSource: string
{
    case Manual = 'manual';
    case Marketplace = 'marketplace';
    case Api = 'api';
    case Automation = 'automation';
}
