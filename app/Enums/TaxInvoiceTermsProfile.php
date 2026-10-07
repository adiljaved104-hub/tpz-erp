<?php

namespace App\Enums;

enum TaxInvoiceTermsProfile: string
{
    case Auto = 'auto';
    case Standard = 'standard';
    case Renewed = 'renewed';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
