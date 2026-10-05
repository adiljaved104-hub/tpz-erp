<?php

namespace App\Enums;

enum QualityControlCosmeticGrade: string
{
    case Premium = 'a_plus';
    case Excellent = 'a';
    case VeryGood = 'b';
    case Good = 'c';

    public function label(): string
    {
        return match ($this) {
            self::Premium => 'A+ / Premium',
            self::Excellent => 'A / Excellent',
            self::VeryGood => 'B / Very Good',
            self::Good => 'C / Good',
        };
    }
}
