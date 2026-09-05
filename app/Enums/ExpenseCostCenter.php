<?php

namespace App\Enums;

enum ExpenseCostCenter: string
{
    case WebSales = 'web_sales';
    case Marketplace = 'marketplace';
    case General = 'general';
    case Other = 'other';

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->headline()->toString();
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
