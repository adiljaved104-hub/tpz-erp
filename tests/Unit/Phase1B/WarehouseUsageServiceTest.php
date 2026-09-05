<?php

namespace Tests\Unit\Phase1B;

use App\Models\Warehouse;
use App\Services\WarehouseUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseUsageServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_result_is_structured_and_unused_without_operational_inventory(): void
    {
        $result = app(WarehouseUsageService::class)->check(Warehouse::factory()->create());

        $this->assertFalse($result->used);
        $this->assertSame([], $result->sources);
    }
}
