<?php

namespace Tests\Unit\Phase1B;

use App\Models\Supplier;
use App\Services\SupplierUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierUsageServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_phase_1b_result_is_structured_and_unused_without_queries(): void
    {
        $result = app(SupplierUsageService::class)->check(Supplier::factory()->create());

        $this->assertFalse($result->used);
        $this->assertSame([], $result->sources);
    }
}
