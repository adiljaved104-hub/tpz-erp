<?php

namespace App\Enums;

enum MyWorkPriority: string
{
    case Critical = 'critical';
    case High = 'high';
    case Normal = 'normal';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function rank(): int
    {
        return match ($this) {
            self::Critical => 1,
            self::High => 2,
            self::Normal => 3,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Critical => 'danger',
            self::High => 'warning',
            self::Normal => 'gray',
        };
    }
}
