<?php

namespace App\Services\Purchases;

use App\DTOs\Purchases\PurchaseItemData;
use App\Exceptions\ExactDecimalUnavailableException;
use InvalidArgumentException;

class PurchaseTotalsCalculator
{
    public function __construct()
    {
        if (! extension_loaded('bcmath')) {
            throw new ExactDecimalUnavailableException('BCMath is required for exact Purchase calculations.');
        }
    }

    /** @return array<string, string|int|null> */
    public function line(PurchaseItemData $item): array
    {
        if ($item->orderedQuantity <= 0) {
            throw new InvalidArgumentException('Ordered quantity must be positive.');
        }

        $unitCost = $this->normalize($item->unitCost, 4);
        $discount = $this->normalize($item->lineDiscountTotal, 2);
        $vatRate = $this->normalize($item->vatRate, 2);
        $baseExact = bcmul($unitCost, (string) $item->orderedQuantity, 4);
        $netExact = bcsub($baseExact, $discount, 4);

        if (bccomp($netExact, '0.0000', 4) < 0 || bccomp($vatRate, '100.00', 2) > 0) {
            throw new InvalidArgumentException('Purchase discount or VAT rate is invalid.');
        }

        $vatExact = bcdiv(bcmul($netExact, $vatRate, 8), '100', 8);
        $lineNet = $this->round($netExact, 2);
        $vatAmount = $this->round($vatExact, 2);

        return [
            'product_id' => $item->productId,
            'ordered_quantity' => $item->orderedQuantity,
            'received_quantity' => 0,
            'rejected_quantity' => 0,
            'unit_cost' => $unitCost,
            'line_discount_total' => $discount,
            'inventory_unit_cost' => $this->divideHalfUp($netExact, (string) $item->orderedQuantity, 4),
            'vat_rate' => $vatRate,
            'vat_amount' => $vatAmount,
            'line_subtotal' => $this->round($baseExact, 2),
            'line_net' => $lineNet,
            'line_total' => bcadd($lineNet, $vatAmount, 2),
            'notes' => $item->notes === null ? null : trim($item->notes),
        ];
    }

    /** @param array<int, array<string, string|int|null>> $lines
     * @return array<string, string>
     */
    public function document(array $lines, string $shippingTotal, string $shippingVatRate, string $otherChargesTotal, string $otherChargesVatRate): array
    {
        $subtotal = $discount = $net = $lineVat = '0.00';

        foreach ($lines as $line) {
            $subtotal = bcadd($subtotal, (string) $line['line_subtotal'], 2);
            $discount = bcadd($discount, (string) $line['line_discount_total'], 2);
            $net = bcadd($net, (string) $line['line_net'], 2);
            $lineVat = bcadd($lineVat, (string) $line['vat_amount'], 2);
        }

        $shipping = $this->normalize($shippingTotal, 2);
        $shippingRate = $this->normalize($shippingVatRate, 2);
        $other = $this->normalize($otherChargesTotal, 2);
        $otherRate = $this->normalize($otherChargesVatRate, 2);

        if (bccomp($shippingRate, '100.00', 2) > 0 || bccomp($otherRate, '100.00', 2) > 0) {
            throw new InvalidArgumentException('Header VAT rate cannot exceed 100%.');
        }

        $shippingVat = $this->round(bcdiv(bcmul($shipping, $shippingRate, 6), '100', 6), 2);
        $otherVat = $this->round(bcdiv(bcmul($other, $otherRate, 6), '100', 6), 2);
        $vatTotal = bcadd(bcadd($lineVat, $shippingVat, 2), $otherVat, 2);

        return [
            'subtotal' => $subtotal,
            'discount_total' => $discount,
            'net_before_vat' => $net,
            'shipping_total' => $shipping,
            'shipping_vat_rate' => $shippingRate,
            'shipping_vat_amount' => $shippingVat,
            'other_charges_total' => $other,
            'other_charges_vat_rate' => $otherRate,
            'other_charges_vat_amount' => $otherVat,
            'vat_total' => $vatTotal,
            'grand_total' => bcadd(bcadd(bcadd($net, $shipping, 2), $other, 2), $vatTotal, 2),
        ];
    }

    public function normalize(string $value, int $scale): string
    {
        $value = trim($value);
        $pattern = $scale === 4 ? '/^\d{1,11}(?:\.\d{1,4})?$/' : '/^\d{1,13}(?:\.\d{1,2})?$/';

        if (! preg_match($pattern, $value)) {
            throw new InvalidArgumentException("Invalid non-negative decimal with {$scale} decimal places.");
        }

        return bcadd($value, '0', $scale);
    }

    public function round(string $value, int $scale): string
    {
        $increment = $scale === 4 ? '0.00005' : '0.005';

        return bcadd($value, $increment, $scale);
    }

    public function divideHalfUp(string $dividend, string $divisor, int $scale): string
    {
        if (bccomp($divisor, '0', 8) === 0) {
            throw new InvalidArgumentException('Division by zero is not allowed.');
        }

        return $this->round(bcdiv($dividend, $divisor, $scale + 5), $scale);
    }
}
