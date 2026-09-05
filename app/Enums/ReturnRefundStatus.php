<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ReturnRefundStatus: string implements HasLabel
{
    case Pending = 'refund_pending';
    case Refunded = 'refunded';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Refund Pending',
            self::Refunded => 'Refunded',
        };
    }
}
