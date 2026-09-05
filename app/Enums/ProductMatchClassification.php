<?php

namespace App\Enums;

enum ProductMatchClassification: string
{
    case Exact = 'exact';
    case VeryHigh = 'very_high';
    case High = 'high';
    case Similar = 'similar';
    case PossibleDuplicate = 'possible_duplicate';
    case BuildableConfiguration = 'buildable_configuration';
    case LowConfidence = 'low_confidence';

    public function label(): string
    {
        return match ($this) {
            self::Exact => 'Exact Match',
            self::VeryHigh => 'Very High Match',
            self::High => 'Good Match',
            self::Similar => 'Similar Product',
            self::PossibleDuplicate => 'Possible Duplicate',
            self::BuildableConfiguration => 'Can Be Built From Current Stock',
            self::LowConfidence => 'Low Confidence',
        };
    }
}
