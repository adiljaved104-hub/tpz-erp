<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DamagedStockStatus: string implements HasLabel
{
    case Damaged = 'damaged';
    case Resolved = 'resolved';

    public function getLabel(): string
    {
        return match ($this) {
            self::Damaged => 'Damaged',
            self::Resolved => 'Resolved',
        };
    }
}
