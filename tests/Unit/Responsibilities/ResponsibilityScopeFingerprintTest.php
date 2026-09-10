<?php

namespace Tests\Unit\Responsibilities;

use App\Enums\ResponsibilityAssignmentMode;
use App\Services\Responsibilities\ResponsibilityScopeFingerprint;
use PHPUnit\Framework\TestCase;

class ResponsibilityScopeFingerprintTest extends TestCase
{
    public function test_fingerprint_is_deterministic_and_each_exact_combination_is_distinct(): void
    {
        $service = new ResponsibilityScopeFingerprint;
        $first = $service->make(1, ResponsibilityAssignmentMode::Scope, 2, 3, null, null);

        $this->assertSame($first, $service->make(1, ResponsibilityAssignmentMode::Scope, 2, 3, null, null));
        $this->assertNotSame($first, $service->make(1, ResponsibilityAssignmentMode::Scope, 2, 4, null, null));
        $this->assertNotSame($first, $service->make(2, ResponsibilityAssignmentMode::Scope, 2, 3, null, null));
        $this->assertNotSame($first, $service->make(1, ResponsibilityAssignmentMode::Quantity, null, 3, null, 9));
        $this->assertSame($first, hash('sha256', json_encode([
            'employee_id' => 1,
            'mode' => 'scope',
            'brand_id' => 2,
            'platform_id' => 3,
            'product_id' => null,
            'product_inventory_id' => null,
        ], JSON_THROW_ON_ERROR)));
        $this->assertNotSame($first, $service->make(1, ResponsibilityAssignmentMode::Scope, null, 3, null, null, 2));
    }
}
