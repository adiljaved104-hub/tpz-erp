<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SafetClaimStatus: string implements HasColor, HasLabel
{
    case NeedsFiling = 'needs_filing';
    case Filed = 'filed';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Paid = 'paid';
    case Closed = 'closed';
    case NotEligible = 'not_eligible';

    public function getLabel(): string
    {
        return match ($this) {
            self::NeedsFiling => 'Needs Filing', self::Filed => 'Filed', self::InReview => 'In Review',
            self::Approved => 'Approved', self::Rejected => 'Rejected', self::Paid => 'Paid',
            self::Closed => 'Closed', self::NotEligible => 'Not Eligible',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NeedsFiling, self::Filed, self::InReview => 'warning',
            self::Approved, self::Paid => 'success',
            self::Rejected => 'danger',
            self::Closed, self::NotEligible => 'gray',
        };
    }
}
