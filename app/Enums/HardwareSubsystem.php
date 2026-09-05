<?php

namespace App\Enums;

enum HardwareSubsystem: string
{
    case Ram = 'ram';
    case Storage = 'storage';

    public function label(): string
    {
        return match ($this) {
            self::Ram => 'RAM',
            self::Storage => 'Storage',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
