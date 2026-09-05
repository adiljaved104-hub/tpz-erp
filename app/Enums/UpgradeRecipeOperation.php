<?php

namespace App\Enums;

enum UpgradeRecipeOperation: string
{
    case Keep = 'keep';
    case Install = 'install';
    case RemoveAndReturn = 'remove_and_return';
    case RemoveAsDamaged = 'remove_as_damaged';
    case RemoveAndDiscard = 'remove_and_discard';

    public function label(): string
    {
        return match ($this) {
            self::Keep => 'Keep Existing Component',
            self::Install => 'Install Component',
            self::RemoveAndReturn => 'Remove and Return to Inventory',
            self::RemoveAsDamaged => 'Remove as Damaged',
            self::RemoveAndDiscard => 'Remove and Discard',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }

    public function isRemoval(): bool
    {
        return in_array($this, [self::RemoveAndReturn, self::RemoveAsDamaged, self::RemoveAndDiscard], true);
    }
}
