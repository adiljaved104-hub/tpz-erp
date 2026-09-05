<?php

namespace App\Enums;

enum CustomerReturnReason: string
{
    case CustomerChangedMind = 'customer_changed_mind';
    case WrongItem = 'wrong_item';
    case Defective = 'defective';
    case DamagedByCustomer = 'damaged_by_customer';
    case NotAsDescribed = 'not_as_described';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CustomerChangedMind => 'Customer Changed Mind', self::WrongItem => 'Wrong Item',
            self::Defective => 'Defective', self::DamagedByCustomer => 'Damaged by Customer',
            self::NotAsDescribed => 'Not as Described', self::Other => 'Other',
        };
    }
}
