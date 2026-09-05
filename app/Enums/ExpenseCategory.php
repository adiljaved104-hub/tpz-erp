<?php

namespace App\Enums;

enum ExpenseCategory: string
{
    case EmployeeSalaries = 'employee_salaries';
    case Advertising = 'advertising';
    case CourierCharges = 'courier_charges';
    case OfficeRent = 'office_rent';
    case Utilities = 'utilities';
    case MarketplaceWebsiteCosts = 'marketplace_website_costs';
    case Transportation = 'transportation';
    case Repairs = 'repairs';
    case Miscellaneous = 'miscellaneous';

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->headline()->toString();
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
