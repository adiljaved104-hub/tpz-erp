<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ComplaintResolution: string implements HasLabel
{
    case AccessorySent = 'accessory_sent';
    case ReplacementArranged = 'replacement_arranged';
    case ItemSentForRepair = 'item_sent_for_repair';
    case NoFaultFound = 'no_fault_found';
    case CustomerGuided = 'customer_guided';
    case MarketplaceResolved = 'marketplace_resolved';
    case RefundReturnHandled = 'refund_return_handled';
    case Other = 'other';

    public function getLabel(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }
}
