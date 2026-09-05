<?php

namespace App\Enums;

enum EmployeeLoanStatus: string
{
    case Open = 'open';
    case PartiallyRepaid = 'partially_repaid';
    case Repaid = 'repaid';
    case Voided = 'voided';

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }
}
