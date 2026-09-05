<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SafetClaimSource: string implements HasLabel
{
    case QcDamagedCustomerReturn = 'qc_damaged_customer_return';

    public function getLabel(): string
    {
        return 'Customer Return – QC Damaged';
    }
}
