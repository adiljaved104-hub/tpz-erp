<?php

namespace App\Support;

final class AedMoney
{
    public static function format(string|int|null $value, string $placeholder = '—'): string
    {
        if ($value === null || trim((string) $value) === '') {
            return $placeholder;
        }

        $value = trim((string) $value);
        $negative = str_starts_with($value, '-');
        $absolute = $negative ? substr($value, 1) : $value;

        if (! preg_match('/^\d+(?:\.\d+)?$/', $absolute)) {
            return $placeholder;
        }

        $rounded = bcadd($absolute, '0.005', 2);
        [$whole, $decimal] = array_pad(explode('.', $rounded, 2), 2, '00');
        $amount = number_format((int) $whole, 0, '.', ',').'.'.str_pad($decimal, 2, '0');

        return 'AED '.($negative ? '-' : '').$amount;
    }
}
