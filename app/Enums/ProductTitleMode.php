<?php

namespace App\Enums;

enum ProductTitleMode: string
{
    case Auto = 'auto';
    case Marketplace = 'marketplace';
    case Website = 'website';
    case Accounting = 'accounting';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'Auto',
            self::Marketplace => 'Marketplace / Platform',
            self::Website => 'Website',
            self::Accounting => 'Accounting / Short',
            self::Custom => 'Custom',
        };
    }
}
