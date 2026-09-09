<?php

namespace App\Enums;

enum QuotationItemSourceType: string
{
    case ExistingProduct = 'existing_product';
    case ManualSourced = 'manual_sourced';

    public function label(): string
    {
        return match ($this) {
            self::ExistingProduct => 'Existing Product',
            self::ManualSourced => 'Manual Sourced Product',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $type): array => [$type->value => $type->label()])->all();
    }
}
