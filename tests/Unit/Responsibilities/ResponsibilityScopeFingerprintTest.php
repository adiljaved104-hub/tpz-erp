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
    }
}
