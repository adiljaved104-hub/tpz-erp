<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OrderStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Confirmed = 'confirmed';
    case Reserved = 'reserved';
    case Processing = 'processing';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingReview => 'Pending Review',
            self::Confirmed => 'Confirmed',
            self::Reserved => 'Reserved',
            self::Processing => 'Processing',
            self::Fulfilled => 'Fulfilled',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::PendingReview, self::Confirmed => 'warning',
            self::Reserved, self::Processing => 'info',
            self::Fulfilled => 'success',
            self::Cancelled => 'danger',
        };
    }
}
