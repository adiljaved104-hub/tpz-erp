<?php

namespace App\Enums;

enum RecoveryValuationMethod: string
{
    case NotApplicable = 'not_applicable';
    case CentralApproved = 'central_approved';
    case Override = 'override';

    public function label(): string
    {
        return match ($this) {
            self::NotApplicable => 'Not Applicable',
            self::CentralApproved => 'Use Central Approved Recovery Value',
            self::Override => 'Approved Recipe Override',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
