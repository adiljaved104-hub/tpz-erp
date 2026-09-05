<?php

namespace App\Services\Responsibilities;

use App\Enums\ResponsibilityAssignmentMode;

class ResponsibilityScopeFingerprint
{
    public function make(
        int $employeeId,
        ResponsibilityAssignmentMode $mode,
        ?int $brandId,
        ?int $platformId,
        ?int $productId,
        ?int $productInventoryId,
    ): string {
        return hash('sha256', json_encode([
            'employee_id' => $employeeId,
            'mode' => $mode->value,
            'brand_id' => $brandId,
            'platform_id' => $platformId,
            'product_id' => $productId,
            'product_inventory_id' => $productInventoryId,
        ], JSON_THROW_ON_ERROR));
    }
}
