<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum WebSalesChannel: string implements HasLabel
{
    case Website = 'website';
    case WhatsApp = 'whatsapp';
    case WalkIn = 'walk_in';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Website => 'Website',
            self::WhatsApp => 'WhatsApp',
            self::WalkIn => 'Walk-in',
            self::Other => 'Other',
        };
    }
}
