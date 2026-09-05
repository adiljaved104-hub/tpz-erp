<?php

namespace App\Services\Quotations;

use Illuminate\Validation\ValidationException;

class QuotationPricingService
{
    /** @param array<int, array<string, mixed>> $items */
    public function calculate(array $items): array
    {
        $subtotal = $discount = $vat = $grand = '0.00';
        $lines = [];

        foreach (array_values($items) as $index => $item) {
            $quantity = (int) $item['quantity'];
            $unit = number_format((float) $item['unit_price_including_vat'], 2, '.', '');
            $lineDiscount = number_format((float) ($item['discount_amount'] ?? 0), 2, '.', '');
            $rate = number_format((float) ($item['vat_rate'] ?? 5), 4, '.', '');
            $grossBeforeDiscount = bcmul((string) $quantity, $unit, 2);
            if (bccomp($lineDiscount, $grossBeforeDiscount, 2) >= 0) {
                throw ValidationException::withMessages(["items.{$index}.discount_amount" => 'Discount must be less than the line value.']);
            }
            $lineGross = bcsub($grossBeforeDiscount, $lineDiscount, 2);
            $divisor = bcadd('1', bcdiv($rate, '100', 8), 8);
            $lineNet = bcadd(bcdiv($lineGross, $divisor, 6), '0.005', 2);
            $lineVat = bcsub($lineGross, $lineNet, 2);
            $lines[] = [
                ...$item,
                'quantity' => $quantity,
                'unit_price_including_vat' => $unit,
                'discount_amount' => $lineDiscount,
                'vat_rate' => $rate,
                'subtotal_excluding_vat' => $lineNet,
                'vat_amount' => $lineVat,
                'total_including_vat' => $lineGross,
                'line_number' => $index + 1,
            ];
            $subtotal = bcadd($subtotal, $lineNet, 2);
            $discount = bcadd($discount, $lineDiscount, 2);
            $vat = bcadd($vat, $lineVat, 2);
            $grand = bcadd($grand, $lineGross, 2);
        }

        return compact('lines', 'subtotal', 'discount', 'vat', 'grand');
    }
}
