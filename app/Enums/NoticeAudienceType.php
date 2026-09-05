<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum NoticeAudienceType: string implements HasLabel
{
    case All = 'all';
    case Team = 'team';
    case Selected = 'selected';

    public function getLabel(): string
    {
        return match ($this) {
            self::All => 'All Employees',
            self::Team => 'Team',
            self::Selected => 'Selected Employees',
        };
    }
}
