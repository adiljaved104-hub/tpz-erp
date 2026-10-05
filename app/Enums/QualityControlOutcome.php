<?php

namespace App\Enums;

enum QualityControlOutcome: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case NotApplicable = 'na';
}
