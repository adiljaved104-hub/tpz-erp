<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ResponsibilityAssignmentMode: string implements HasLabel
{
    case Scope = 'scope';
    case Quantity = 'quantity';

    public function getLabel(): string
    {
        return match ($this) {
            self::Scope => 'Scope',
            self::Quantity => 'Quantity',
        };
    }
}
