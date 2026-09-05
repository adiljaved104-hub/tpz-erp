<?php

namespace App\Services\Inventory;

use App\Exceptions\ExactDecimalUnavailableException;
use App\Exceptions\InventoryInvariantException;

class WeightedAverageCostCalculator
{
    public function __construct()
    {
        if (! extension_loaded('bcmath')) {
            throw new ExactDecimalUnavailableException('BCMath is required for exact inventory costing.');
        }
    }

    public function weightedAverage(int $existingQuantity, ?string $existingAverage, int $inboundQuantity, string $inboundUnitCost): string
    {
        if ($existingQuantity < 0 || $inboundQuantity <= 0) {
            throw new InventoryInvariantException('Weighted-average quantities are invalid.');
        }

        $inboundScaled = $this->toScaledInteger($inboundUnitCost);

        if ($existingQuantity === 0) {
            return $this->fromScaledInteger($inboundScaled);
        }

        if ($existingAverage === null) {
            throw new InventoryInvariantException('Positive valued inventory cannot have a null average cost.');
        }

        $existingScaled = $this->toScaledInteger($existingAverage);
        $existingValue = bcmul($existingScaled, (string) $existingQuantity, 0);
        $inboundValue = bcmul($inboundScaled, (string) $inboundQuantity, 0);
        $totalValue = bcadd($existingValue, $inboundValue, 0);
        $totalQuantity = (string) ($existingQuantity + $inboundQuantity);

        return $this->fromScaledInteger($this->divideHalfUp($totalValue, $totalQuantity));
    }

    public function inventoryValue(int $quantity, string $averageCost): string
    {
        if ($quantity < 0) {
            throw new InventoryInvariantException('Inventory value quantity cannot be negative.');
        }

        return $this->fromScaledInteger(bcmul($this->toScaledInteger($averageCost), (string) $quantity, 0));
    }

    public function normalize(string $decimal): string
    {
        return $this->fromScaledInteger($this->toScaledInteger($decimal));
    }

    private function toScaledInteger(string $decimal): string
    {
        $decimal = trim($decimal);

        if (! preg_match('/^\d{1,11}(?:\.\d{1,4})?$/', $decimal)) {
            throw new InventoryInvariantException('Inventory cost must be a non-negative decimal with at most four decimal places.');
        }

        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        $scaled = ltrim($whole.str_pad($fraction, 4, '0'), '0');

        return $scaled === '' ? '0' : $scaled;
    }

    private function fromScaledInteger(string $scaled): string
    {
        $scaled = ltrim($scaled, '0');
        $scaled = $scaled === '' ? '0' : $scaled;
        $scaled = str_pad($scaled, 5, '0', STR_PAD_LEFT);

        return substr($scaled, 0, -4).'.'.substr($scaled, -4);
    }

    private function divideHalfUp(string $dividend, string $divisor): string
    {
        $quotient = bcdiv($dividend, $divisor, 0);
        $remainder = bcmod($dividend, $divisor);

        if (bccomp(bcmul($remainder, '2', 0), $divisor, 0) >= 0) {
            return bcadd($quotient, '1', 0);
        }

        return $quotient;
    }
}
