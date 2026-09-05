<?php

namespace App\Support;

use App\DTOs\Reports\ReportResult;

final class ReportValueFormatter
{
    public static function summary(ReportResult $report, string $label, int|float|string $value): string
    {
        if (! is_numeric($value)) {
            return (string) $value;
        }

        if (self::isMoney($label)) {
            $currency = str_starts_with($report->key, 'office_finance.') ? 'PKR' : 'AED';

            return $currency.' '.number_format((float) $value, 2);
        }

        if (is_float($value) && fmod($value, 1.0) !== 0.0) {
            return number_format($value, 2);
        }

        return number_format((int) $value);
    }

    private static function isMoney(string $label): bool
    {
        return str($label)->lower()->contains([
            'amount', 'balance', 'cost', 'expense', 'funding', 'gross profit',
            'inventory value', 'money in', 'money out', 'net profit', 'revenue',
            'sales value', 'total value',
        ]);
    }
}
