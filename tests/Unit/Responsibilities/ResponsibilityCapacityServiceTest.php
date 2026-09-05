<?php

namespace Tests\Unit\Responsibilities;

use App\Enums\ResponsibilityCapacityStatus;
use App\Exceptions\ResponsibilityCapacityExceededException;
use App\Services\Responsibilities\ResponsibilityCapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class ResponsibilityCapacityServiceTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_capacity_uses_sellable_and_excludes_damaged(): void
    {
        $foundation = $this->responsibilityFoundation(20, 5);
        $foundation['inventory']->forceFill(['damaged_quantity' => 100])->save();
        $result = app(ResponsibilityCapacityService::class)->lockAndAssert($foundation['inventory']->id, 15);

        $this->assertSame(15, $result['sellable']);
        $this->assertSame(0, $result['remaining']);
        $this->assertSame(ResponsibilityCapacityStatus::AtCapacity, $result['status']);

        $this->expectException(ResponsibilityCapacityExceededException::class);
        app(ResponsibilityCapacityService::class)->lockAndAssert($foundation['inventory']->id, 16);
    }
}
