<?php

namespace App\Services\Mobile;

class StockStatus
{
    public function low(): int
    {
        return max(1, (int) config('mobile.low_stock_threshold', 2));
    }

    public function critical(): int
    {
        return min($this->low(), max(0, (int) config('mobile.critical_stock_threshold', 1)));
    }

    public function forQuantity(int $quantity): string
    {
        return match (true) {
            $quantity <= 0 => 'out_of_stock',
            $quantity <= $this->critical() => 'critical',
            $quantity <= $this->low() => 'low_stock',
            default => 'in_stock',
        };
    }
}
