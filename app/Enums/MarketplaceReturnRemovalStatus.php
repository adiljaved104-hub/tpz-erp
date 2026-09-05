<?php

namespace App\Enums;

enum MarketplaceReturnRemovalStatus: string
{
    case Draft = 'draft';
    case Requested = 'requested';
    case Dispatched = 'dispatched';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }
}
