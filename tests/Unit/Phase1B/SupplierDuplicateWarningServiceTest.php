<?php

namespace Tests\Unit\Phase1B;

use App\Services\SupplierDuplicateWarningService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class SupplierDuplicateWarningServiceTest extends TestCase
{
    public function test_service_has_no_merge_or_write_method(): void
    {
        $methods = collect((new ReflectionClass(SupplierDuplicateWarningService::class))->getMethods())->pluck('name');

        $this->assertTrue($methods->contains('candidates'));
        $this->assertFalse($methods->contains('merge'));
        $this->assertFalse($methods->contains('create'));
        $this->assertFalse($methods->contains('update'));
    }
}
