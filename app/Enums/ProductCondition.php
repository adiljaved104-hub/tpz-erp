<?php

namespace App\Enums;

enum ProductCondition: string
{
    case New = 'new';
    case Renewed = 'renewed';
    case Used = 'used';
    case OpenBox = 'open_box';
    case Refurbished = 'refurbished';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Renewed => 'Renewed',
            self::Used => 'Used',
            self::OpenBox => 'Open Box',
            self::Refurbished => 'Refurbished',
        };
    }
}
