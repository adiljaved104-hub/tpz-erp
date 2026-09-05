<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TaskAssignmentMode: string implements HasLabel
{
    case SingleEmployee = 'single_employee';
    case MultipleEmployees = 'multiple_employees';
    case EntireTeam = 'entire_team';
    case TeamQueue = 'team_queue';

    public function getLabel(): string
    {
        return match ($this) {
            self::SingleEmployee => 'Single Employee',
            self::MultipleEmployees => 'Multiple Employees',
            self::EntireTeam => 'Entire Team',
            self::TeamQueue => 'Team Queue',
        };
    }
}
