<?php

namespace App\Enums;

enum OfficeFinanceExpenseCategory: string
{
    case Salaries = 'salaries';
    case Advertising = 'advertising';
    case Grocery = 'grocery';
    case Courier = 'courier';
    case Utilities = 'utilities';
    case OfficeRent = 'office_rent';
    case Transportation = 'transportation';
    case Repairs = 'repairs';
    case InternetTelecom = 'internet_telecom';
    case OfficeSupplies = 'office_supplies';
    case Miscellaneous = 'miscellaneous';

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
