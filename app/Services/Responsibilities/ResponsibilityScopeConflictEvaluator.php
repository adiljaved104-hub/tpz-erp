<?php

namespace App\Services\Responsibilities;

use App\DTOs\Responsibilities\CreateResponsibilityAssignmentData;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Detects whether two active assignments can apply to the same operational
 * context, or would select different default holders for the same stock.
 */
class ResponsibilityScopeConflictEvaluator
{
    /** @return Collection<int, array{assignment: ResponsibilityAssignment, operational: bool, defaultStock: bool}> */
    public function conflicts(CreateResponsibilityAssignmentData $proposed, array $exceptAssignmentIds = [], bool $lock = false, bool $checkOperational = true): Collection
    {
        $candidate = $this->fromData($proposed);
        $query = ResponsibilityAssignment::query()->active()
            ->whereHas('employee', fn ($employee) => $employee->where('status', true)->whereNotNull('user_id'))
            ->with(['employee', 'brandScope.brand', 'categoryScope.category', 'platformScope.platform', 'productScope.product', 'quantityScope.inventory.product', 'quantityScope.inventory.warehouse', 'warehouseScope.warehouse', 'conditionScope']);

        if ($exceptAssignmentIds !== []) {
            $query->whereNotIn('id', $exceptAssignmentIds);
        }
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get()->map(function (ResponsibilityAssignment $assignment) use ($candidate, $proposed, $checkOperational): ?array {
            if ($assignment->employee_id === $proposed->employeeId) {
                return null;
            }

            $existing = $this->fromAssignment($assignment);
            $operational = $checkOperational
                && $proposed->mode->value === 'scope'
                && $assignment->assignment_mode->value === 'scope'
                && $this->intersects($candidate, $existing, includePlatform: true);
            $defaultStock = $proposed->assignStockByDefault
                && $assignment->assign_stock_by_default
                && $this->defaultStockScopesIntersect($candidate, $existing);

            if (! $operational && ! $defaultStock) {
                return null;
            }

            return compact('assignment', 'operational', 'defaultStock');
        })->filter()->values();
    }

    public function assertNoConflicts(CreateResponsibilityAssignmentData $proposed, array $exceptAssignmentIds = [], bool $lock = false, bool $checkOperational = true): void
    {
        $conflicts = $this->conflicts($proposed, $exceptAssignmentIds, $lock, $checkOperational);
        if ($conflicts->isEmpty()) {
            return;
        }

        $messages = $conflicts->map(function (array $conflict): string {
            $assignment = $conflict['assignment'];
            $employee = $assignment->employee?->name ?? 'another Employee';
            $scope = $this->describe($assignment);
            $types = collect([
                $conflict['operational'] ? 'operational responsibility' : null,
                $conflict['defaultStock'] ? 'default stock holder' : null,
            ])->filter()->implode(' and ');

            return "{$employee} ({$assignment->reference}) already has an overlapping {$types}: {$scope}. Resolve or explicitly transfer that scope first.";
        });

        throw ValidationException::withMessages(['scope' => $messages->all()]);
    }

    public function preview(CreateResponsibilityAssignmentData $proposed, array $exceptAssignmentIds = []): string
    {
        $conflicts = $this->conflicts($proposed, $exceptAssignmentIds);
        if ($conflicts->isEmpty()) {
            return 'SAFE — No conflicting active Responsibility was found.';
        }

        return 'CONFLICT — '.collect($conflicts)->map(function (array $conflict): string {
            $assignment = $conflict['assignment'];
            $type = $conflict['defaultStock'] ? 'default stock holder' : 'operational scope';

            return ($assignment->employee?->name ?? 'Another Employee')." has an overlapping {$type} ({$this->describe($assignment)}).";
        })->implode(' ');
    }

    /**
     * Whether a default-stock Responsibility applies to this physical receipt.
     * Marketplace platform is deliberately ignored: it is operational context,
     * while receipt ownership is determined by physical product scope.
     */
    public function matchesDefaultStockForInventory(ResponsibilityAssignment $assignment, ProductInventory $inventory): bool
    {
        if (! $assignment->assign_stock_by_default || $assignment->assignment_mode->value !== 'scope') {
            return false;
        }

        $assignment->loadMissing(['productScope', 'brandScope', 'categoryScope', 'conditionScope', 'warehouseScope']);
        $inventory->loadMissing('product');
        $product = $inventory->product;

        if ($product === null) {
            return false;
        }

        $scope = $this->fromAssignment($assignment);
        if (! $this->valuesOverlap($scope['condition'], $product->condition?->value)
            || ! $this->valuesOverlap($scope['warehouse_id'], $inventory->warehouse_id)) {
            return false;
        }

        return $this->physicalSources($scope) !== []
            && ($scope['product_id'] === null || (int) $scope['product_id'] === (int) $product->id)
            && ($scope['brand_id'] === null || (int) $scope['brand_id'] === (int) $product->brand_id)
            && ($scope['category_id'] === null || (int) $scope['category_id'] === (int) $product->category_id);
    }

    private function fromData(CreateResponsibilityAssignmentData $data): array
    {
        $productId = $data->productId;
        $warehouseId = $data->warehouseId;
        if ($data->productInventoryId !== null) {
            $inventory = DB::table('product_inventories')->where('id', $data->productInventoryId)->first(['product_id', 'warehouse_id']);
            $productId ??= $inventory === null ? null : (int) $inventory->product_id;
            $warehouseId ??= $inventory === null ? null : (int) $inventory->warehouse_id;
        }

        return [
            'employee_id' => $data->employeeId,
            'product_id' => $productId,
            'brand_id' => $data->brandId,
            'category_id' => $data->categoryId,
            'condition' => $data->condition?->value,
            'warehouse_id' => $warehouseId,
            'platform_id' => $data->platformId,
            'assign_stock_by_default' => $data->assignStockByDefault,
        ];
    }

    private function fromAssignment(ResponsibilityAssignment $assignment): array
    {
        $inventory = $assignment->quantityScope?->inventory;

        return [
            'employee_id' => $assignment->employee_id,
            'product_id' => $assignment->productScope?->product_id ?? $inventory?->product_id,
            'brand_id' => $assignment->brandScope?->product_brand_id,
            'category_id' => $assignment->categoryScope?->product_category_id,
            'condition' => $assignment->conditionScope?->product_condition?->value,
            'warehouse_id' => $assignment->warehouseScope?->warehouse_id ?? $inventory?->warehouse_id,
            'platform_id' => $assignment->platformScope?->marketplace_platform_id,
            'assign_stock_by_default' => $assignment->assign_stock_by_default,
        ];
    }

    private function intersects(array $left, array $right, bool $includePlatform): bool
    {
        if ($includePlatform && ! $this->valuesOverlap($left['platform_id'], $right['platform_id'])) {
            return false;
        }
        if (! $this->valuesOverlap($left['warehouse_id'], $right['warehouse_id'])) {
            return false;
        }

        $leftProduct = $left['product_id'] === null ? null : $this->product((int) $left['product_id']);
        $rightProduct = $right['product_id'] === null ? null : $this->product((int) $right['product_id']);
        if ($leftProduct !== null && $rightProduct !== null && $leftProduct->id !== $rightProduct->id) {
            return false;
        }
        if ($leftProduct !== null && ! $this->productMatches($leftProduct, $right)) {
            return false;
        }
        if ($rightProduct !== null && ! $this->productMatches($rightProduct, $left)) {
            return false;
        }

        if ($leftProduct !== null || $rightProduct !== null) {
            return true;
        }

        foreach (['brand_id', 'category_id', 'condition'] as $dimension) {
            if (! $this->valuesOverlap($left[$dimension], $right[$dimension])) {
                return false;
            }
        }

        return true;
    }

    private function productMatches(Product $product, array $scope): bool
    {
        return ($scope['brand_id'] === null || (int) $scope['brand_id'] === (int) $product->brand_id)
            && ($scope['category_id'] === null || (int) $scope['category_id'] === (int) $product->category_id)
            && ($scope['condition'] === null || $scope['condition'] === $product->condition?->value);
    }

    private function defaultStockScopesIntersect(array $left, array $right): bool
    {
        foreach (['condition', 'warehouse_id', 'product_id', 'brand_id', 'category_id'] as $dimension) {
            if (! $this->valuesOverlap($left[$dimension], $right[$dimension])) {
                return false;
            }
        }

        if ($left['product_id'] !== null) {
            $product = $this->product((int) $left['product_id']);

            return $this->scopeMatchesProduct($left, $product) && $this->scopeMatchesProduct($right, $product);
        }
        if ($right['product_id'] !== null) {
            $product = $this->product((int) $right['product_id']);

            return $this->scopeMatchesProduct($left, $product) && $this->scopeMatchesProduct($right, $product);
        }

        // Populated dimensions are conjunctive; with no contradictory values,
        // a product can match the combined scope, including one added later.
        return $this->physicalSources($left) !== [] && $this->physicalSources($right) !== [];
    }

    private function scopeMatchesProduct(array $scope, ?Product $product): bool
    {
        return $product !== null
            && ($scope['product_id'] === null || (int) $scope['product_id'] === (int) $product->id)
            && ($scope['brand_id'] === null || (int) $scope['brand_id'] === (int) $product->brand_id)
            && ($scope['category_id'] === null || (int) $scope['category_id'] === (int) $product->category_id)
            && $this->valuesOverlap($scope['condition'], $product->condition?->value);
    }

    /** @return array<string, int> */
    private function physicalSources(array $scope): array
    {
        $sources = [];
        foreach ([
            'product_id' => 'product',
            'brand_id' => 'brand',
            'category_id' => 'category',
        ] as $key => $type) {
            if ($scope[$key] !== null) {
                $sources[$type] = (int) $scope[$key];
            }
        }

        return $sources;
    }

    private function product(int $id): ?Product
    {
        return Product::query()->find($id);
    }

    private function valuesOverlap(mixed $left, mixed $right): bool
    {
        return $left === null || $right === null || (string) $left === (string) $right;
    }

    private function describe(ResponsibilityAssignment $assignment): string
    {
        $parts = collect([
            $assignment->productScope?->product?->sku,
            $assignment->brandScope?->brand?->name ? 'Brand '.$assignment->brandScope->brand->name : null,
            $assignment->categoryScope?->category?->name ? 'Category '.$assignment->categoryScope->category->name : null,
            $assignment->conditionScope?->product_condition?->label() ? 'Condition '.$assignment->conditionScope->product_condition->label() : null,
            $assignment->warehouseScope?->warehouse?->name ? 'Warehouse '.$assignment->warehouseScope->warehouse->name : null,
            $assignment->quantityScope?->inventory?->product?->sku,
            $assignment->platformScope?->platform?->name ? 'Platform '.$assignment->platformScope->platform->name : null,
        ])->filter()->values();

        return $parts->isEmpty() ? 'all applicable products' : $parts->implode(' · ');
    }
}
