<?php

namespace App\Enums;

enum QuotationDocumentType: string
{
    case Quotation = 'quotation';
    case ProformaInvoice = 'proforma_invoice';

    public function label(): string
    {
        return $this === self::Quotation ? 'Quotation' : 'Proforma Invoice';
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
