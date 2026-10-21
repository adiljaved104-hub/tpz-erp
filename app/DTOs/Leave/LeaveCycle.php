<?php

namespace App\DTOs\Leave;

use Carbon\CarbonImmutable;

final readonly class LeaveCycle
{
    public function __construct(public CarbonImmutable $start, public CarbonImmutable $end, public int $completedYears) {}
}
