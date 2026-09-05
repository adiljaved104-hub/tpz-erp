<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum WarningLevel: string implements HasLabel
{
    case Verbal = 'verbal';
    case Written = 'written';
    case Final = 'final';

    public function getLabel(): string
    {
        return match ($this) {
            self::Verbal => 'Verbal Warning',
            self::Written => 'Written Warning',
            self::Final => 'Final Warning',
        };
    }
}
