<?php

namespace App\Services\Orders;

use App\DTOs\Orders\OrderItemData;
use App\Exceptions\ExactDecimalUnavailableException;

class OrderTotalsCalculator
{
    /** @return array{subtotal:string,discount_total:string,vat_total:string,grand_total:string,lines:array<int,array<string,string|int>>} */
    public function calculate(array $items): array
    {
        if (! function_exists('bcmul')) {
            throw new ExactDecimalUnavailableException('BCMath is required for exact Order calculations.');
        }

        $subtotal = $discount = $vat = $grand = '0.00';
        $lines = [];

        foreach ($items as $item) {
            if (! $item instanceof OrderItemData) {
                continue;
            }

            $gross = bcmul((string) $item->quantity, $item->sellingPrice, 2);
            $net = bcsub($gross, $item->discountTotal, 2);
            $vatAmount = bcdiv(bcmul($net, $item->vatRate, 6), '100', 2);
            $lineTotal = bcadd($net, $vatAmount, 2);
            $subtotal = bcadd($subtotal, $gross, 2);
            $discount = bcadd($discount, $item->discountTotal, 2);
            $vat = bcadd($vat, $vatAmount, 2);
            $grand = bcadd($grand, $lineTotal, 2);
            $lines[] = [
                'product_id' => $item->productId,
                'ordered_quantity' => $item->quantity,
                'selling_price' => $this->money($item->sellingPrice),
                'discount_total' => $this->money($item->discountTotal),
                'vat_rate' => bcadd($item->vatRate, '0', 4),
                'vat_amount' => $vatAmount,
                'line_total' => $lineTotal,
                'notes' => $item->notes,
            ];
        }

        return [
            'subtotal' => $subtotal,
            'discount_total' => $discount,
            'vat_total' => $vat,
            'grand_total' => $grand,
            'lines' => $lines,
        ];
    }

    private function money(string $value): string
    {
        return bcadd($value, '0', 2);
    }
}
