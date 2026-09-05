<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ResponsibilityCapacityStatus: string implements HasColor, HasLabel
{
    case Ok = 'ok';
    case AtCapacity = 'at_capacity';
    case OverAssigned = 'over_assigned';
    case NotApplicable = 'not_applicable';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ok => 'OK',
            self::AtCapacity => 'At Capacity',
            self::OverAssigned => 'Over Assigned',
            self::NotApplicable => 'Not Applicable',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::AtCapacity => 'warning',
            self::OverAssigned => 'danger',
            self::NotApplicable => 'gray',
        };
    }
}
