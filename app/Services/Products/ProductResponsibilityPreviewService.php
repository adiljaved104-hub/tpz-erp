<?php

namespace App\Services\Products;

use App\Enums\EmployeeRole;
use App\Enums\ResponsibilityAssignmentStatus;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Read-only preview of whether a proposed product will remain visible to its editor. */
class ProductResponsibilityPreviewService
{
    /** @param array<string, mixed> $attributes */
    public function isVisible(User $user, array $attributes, ?int $productId = null): bool
    {
        if (! in_array($user->employee?->role, [EmployeeRole::Manager, EmployeeRole::Staff], true)) {
            return true;
        }

        $employeeId = $user->employee?->id;
        if ($employeeId === null || $user->employee?->status !== true || $user->employee?->user_id !== $user->id) {
            return false;
        }

        $brandId = filled($attributes['brand_id'] ?? null) ? (int) $attributes['brand_id'] : null;
        $categoryId = filled($attributes['category_id'] ?? null) ? (int) $attributes['category_id'] : null;
        $condition = is_string($attributes['condition'] ?? null)
            ? $attributes['condition']
            : ($attributes['condition'] instanceof \BackedEnum ? $attributes['condition']->value : null);

        if ($this->matchesDimensionOnlyAssignment($employeeId, $brandId, $categoryId, $condition)) {
            return true;
        }

        if ($productId === null) {
            return false;
        }

        $product = Product::query()->find($productId);
        if ($product === null) {
            return false;
        }

        // Preserve direct-product responsibility only when every additional restriction still matches.
        $direct = DB::table('responsibility_assignments as preview_ra')
            ->where('preview_ra.employee_id', $employeeId)
            ->where('preview_ra.status', ResponsibilityAssignmentStatus::Active->value)
            ->whereNull('preview_ra.ended_at')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('responsibility_assignment_products as preview_product')
                ->whereColumn('preview_product.assignment_id', 'preview_ra.id')->where('preview_product.product_id', $productId));
        $this->matchingAttributeRestrictions($direct, $brandId, $categoryId, $condition);
        if (! $direct->exists()) {
            // A product scoped by quantity/warehouse/platform is visible only while its backing location or listing remains.
            $backed = DB::table('responsibility_assignments as preview_ra')
                ->where('preview_ra.employee_id', $employeeId)
                ->where('preview_ra.status', ResponsibilityAssignmentStatus::Active->value)
                ->whereNull('preview_ra.ended_at')
                ->where(function ($scope) use ($productId): void {
                    $scope->whereExists(fn ($query) => $query->selectRaw('1')->from('inventory_responsibility_quantities as preview_quantity')
                        ->join('product_inventories as preview_quantity_inventory', 'preview_quantity_inventory.id', '=', 'preview_quantity.product_inventory_id')
                        ->whereColumn('preview_quantity.assignment_id', 'preview_ra.id')->where('preview_quantity_inventory.product_id', $productId))
                        ->orWhereExists(fn ($query) => $query->selectRaw('1')->from('responsibility_assignment_warehouses as preview_warehouse')
                            ->join('product_inventories as preview_inventory', 'preview_inventory.warehouse_id', '=', 'preview_warehouse.warehouse_id')
                            ->whereColumn('preview_warehouse.assignment_id', 'preview_ra.id')->where('preview_inventory.product_id', $productId))
                        ->orWhereExists(fn ($query) => $query->selectRaw('1')->from('responsibility_assignment_platforms as preview_platform')
                            ->join('product_marketplace_listings as preview_listing', 'preview_listing.marketplace_platform_id', '=', 'preview_platform.marketplace_platform_id')
                            ->whereColumn('preview_platform.assignment_id', 'preview_ra.id')->where('preview_listing.product_id', $productId));
                });
            $this->matchingAttributeRestrictions($backed, $brandId, $categoryId, $condition);

            return $backed->exists();
        }

        return true;
    }

    private function matchesDimensionOnlyAssignment(int $employeeId, ?int $brandId, ?int $categoryId, ?string $condition): bool
    {
        $query = DB::table('responsibility_assignments as preview_ra')
            ->where('preview_ra.employee_id', $employeeId)
            ->where('preview_ra.status', ResponsibilityAssignmentStatus::Active->value)
            ->whereNull('preview_ra.ended_at')
            ->whereNotExists(fn ($scope) => $scope->selectRaw('1')->from('responsibility_assignment_warehouses as preview_no_warehouse')
                ->whereColumn('preview_no_warehouse.assignment_id', 'preview_ra.id'))
            ->where(function ($scope): void {
                $scope->whereExists(fn ($query) => $query->selectRaw('1')->from('responsibility_assignment_brands as preview_brand_present')->whereColumn('preview_brand_present.assignment_id', 'preview_ra.id'))
                    ->orWhereExists(fn ($query) => $query->selectRaw('1')->from('responsibility_assignment_categories as preview_category_present')->whereColumn('preview_category_present.assignment_id', 'preview_ra.id'))
                    ->orWhereExists(fn ($query) => $query->selectRaw('1')->from('responsibility_assignment_conditions as preview_condition_present')->whereColumn('preview_condition_present.assignment_id', 'preview_ra.id'));
            });

        $this->matchingAttributeRestrictions($query, $brandId, $categoryId, $condition);

        return $query->exists();
    }

    private function matchingAttributeRestrictions($query, ?int $brandId, ?int $categoryId, ?string $condition): void
    {
        foreach ([
            ['responsibility_assignment_brands', 'product_brand_id', $brandId],
            ['responsibility_assignment_categories', 'product_category_id', $categoryId],
            ['responsibility_assignment_conditions', 'product_condition', $condition],
        ] as [$table, $column, $value]) {
            $query->where(function ($scope) use ($table, $column, $value): void {
                $scope->whereNotExists(fn ($none) => $none->selectRaw('1')->from($table.' as preview_none')->whereColumn('preview_none.assignment_id', 'preview_ra.id'));
                if ($value !== null) {
                    $scope->orWhereExists(fn ($match) => $match->selectRaw('1')->from($table.' as preview_match')
                        ->whereColumn('preview_match.assignment_id', 'preview_ra.id')->where('preview_match.'.$column, $value));
                }
            });
        }
    }
}
