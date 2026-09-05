<?php

namespace App\Enums;

enum OfficeFinanceAccountType: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case CompanyCard = 'company_card';
    case Other = 'other';

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
