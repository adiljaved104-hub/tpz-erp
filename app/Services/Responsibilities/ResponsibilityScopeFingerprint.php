<?php

namespace App\Services\Responsibilities;

use App\Enums\ProductCondition;
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
        ?int $categoryId = null,
        ?int $warehouseId = null,
        ?ProductCondition $condition = null,
    ): string {
        $scope = [
            'employee_id' => $employeeId,
            'mode' => $mode->value,
            'brand_id' => $brandId,
            'platform_id' => $platformId,
            'product_id' => $productId,
            'product_inventory_id' => $productInventoryId,
        ];
        if ($categoryId !== null) {
            $scope['category_id'] = $categoryId;
        }
        if ($warehouseId !== null) {
            $scope['warehouse_id'] = $warehouseId;
        }
        if ($condition !== null) {
            $scope['condition'] = $condition->value;
        }

        return hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR));
    }
}
