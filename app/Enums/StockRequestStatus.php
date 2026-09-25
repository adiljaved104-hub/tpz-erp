<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StockRequestStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';

    public function getLabel(): string
    {
        return 'Pending';
    }

    public function getColor(): string|array|null
    {
        return 'warning';
    }
}
