<?php

namespace App\Services\Responsibilities;

use App\Enums\EmployeeRole;
use App\Enums\ResponsibilityAssignmentStatus;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ResponsibilityProductScopeService
{
    public function requiresScope(User $user): bool
    {
        return in_array($user->employee?->role, [EmployeeRole::Manager, EmployeeRole::Staff], true);
    }

    public function apply(Builder $query, string $qualifiedProductColumn, User $user): Builder
    {
        if (! $this->requiresScope($user)) {
            return $query;
        }

        $employeeId = $user->employee?->id;

        if ($employeeId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $scope) use ($employeeId, $qualifiedProductColumn): void {
            $scope->whereExists(function (Builder $direct) use ($employeeId, $qualifiedProductColumn): void {
                $direct->selectRaw('1')
                    ->from('responsibility_assignments as direct_ra')
                    ->join('responsibility_assignment_products as direct_rap', 'direct_rap.assignment_id', '=', 'direct_ra.id')
                    ->where('direct_ra.employee_id', $employeeId)
                    ->where('direct_ra.status', ResponsibilityAssignmentStatus::Active->value)
                    ->whereNull('direct_ra.ended_at')
                    ->whereColumn('direct_rap.product_id', $qualifiedProductColumn);
            })->orWhereExists(function (Builder $quantity) use ($employeeId, $qualifiedProductColumn): void {
                $quantity->selectRaw('1')
                    ->from('responsibility_assignments as quantity_ra')
                    ->join('inventory_responsibility_quantities as quantity_irq', 'quantity_irq.assignment_id', '=', 'quantity_ra.id')
                    ->join('product_inventories as quantity_pi', 'quantity_pi.id', '=', 'quantity_irq.product_inventory_id')
                    ->where('quantity_ra.employee_id', $employeeId)
                    ->where('quantity_ra.status', ResponsibilityAssignmentStatus::Active->value)
                    ->whereNull('quantity_ra.ended_at')
                    ->whereColumn('quantity_pi.product_id', $qualifiedProductColumn);
            })->orWhereExists(function (Builder $brand) use ($employeeId, $qualifiedProductColumn): void {
                $brand->selectRaw('1')
                    ->from('responsibility_assignments as brand_ra')
                    ->join('responsibility_assignment_brands as brand_rab', 'brand_rab.assignment_id', '=', 'brand_ra.id')
                    ->join('products as brand_product', 'brand_product.brand_id', '=', 'brand_rab.product_brand_id')
                    ->where('brand_ra.employee_id', $employeeId)
                    ->where('brand_ra.status', ResponsibilityAssignmentStatus::Active->value)
                    ->whereNull('brand_ra.ended_at')
                    ->whereColumn('brand_product.id', $qualifiedProductColumn);
            });
        });
    }

    public function canAccessProduct(User $user, int $productId): bool
    {
        $query = DB::table('products')->where('products.id', $productId);

        return $this->apply($query, 'products.id', $user)->exists();
    }

    public function applyInventories(Builder $query, string $inventoryAlias, User $user): Builder
    {
        if (! $this->requiresScope($user)) {
            return $query;
        }

        $employeeId = $user->employee?->id;
        if ($employeeId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $this->applyInventoriesForEmployees($query, $inventoryAlias, [$employeeId]);
    }

    /** @param array<int, int> $employeeIds */
    public function applyInventoriesForEmployees(Builder $query, string $inventoryAlias, array $employeeIds): Builder
    {
        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
        if ($employeeIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereExists(function (Builder $assignment) use ($employeeIds, $inventoryAlias): void {
            $assignment->selectRaw('1')
                ->from('responsibility_assignments as inventory_ra')
                ->whereIn('inventory_ra.employee_id', $employeeIds)
                ->where('inventory_ra.status', ResponsibilityAssignmentStatus::Active->value)
                ->whereNull('inventory_ra.ended_at')
                ->where(function (Builder $platform) use ($inventoryAlias): void {
                    $platform->whereNotExists(fn (Builder $scope) => $scope->selectRaw('1')
                        ->from('responsibility_assignment_platforms as inventory_platform_none')
                        ->whereColumn('inventory_platform_none.assignment_id', 'inventory_ra.id'))
                        ->orWhereExists(fn (Builder $scope) => $scope->selectRaw('1')
                            ->from('responsibility_assignment_platforms as inventory_platform_scope')
                            ->join('warehouses as inventory_platform_warehouse', 'inventory_platform_warehouse.marketplace_platform_id', '=', 'inventory_platform_scope.marketplace_platform_id')
                            ->whereColumn('inventory_platform_scope.assignment_id', 'inventory_ra.id')
                            ->whereColumn('inventory_platform_warehouse.id', "{$inventoryAlias}.warehouse_id"));
                })
                ->where(function (Builder $product) use ($inventoryAlias): void {
                    $product->whereExists(fn (Builder $scope) => $scope->selectRaw('1')
                        ->from('responsibility_assignment_products as inventory_product_scope')
                        ->whereColumn('inventory_product_scope.assignment_id', 'inventory_ra.id')
                        ->whereColumn('inventory_product_scope.product_id', "{$inventoryAlias}.product_id"))
                        ->orWhereExists(fn (Builder $scope) => $scope->selectRaw('1')
                            ->from('responsibility_assignment_brands as inventory_brand_scope')
                            ->join('products as inventory_brand_product', 'inventory_brand_product.brand_id', '=', 'inventory_brand_scope.product_brand_id')
                            ->whereColumn('inventory_brand_scope.assignment_id', 'inventory_ra.id')
                            ->whereColumn('inventory_brand_product.id', "{$inventoryAlias}.product_id"))
                        ->orWhereExists(fn (Builder $scope) => $scope->selectRaw('1')
                            ->from('inventory_responsibility_quantities as inventory_quantity_scope')
                            ->whereColumn('inventory_quantity_scope.assignment_id', 'inventory_ra.id')
                            ->whereColumn('inventory_quantity_scope.product_inventory_id', "{$inventoryAlias}.id"))
                        ->orWhere(function (Builder $platformOnly): void {
                            $platformOnly->whereExists(fn (Builder $scope) => $scope->selectRaw('1')->from('responsibility_assignment_platforms as inventory_platform_only')->whereColumn('inventory_platform_only.assignment_id', 'inventory_ra.id'))
                                ->whereNotExists(fn (Builder $scope) => $scope->selectRaw('1')->from('responsibility_assignment_products as inventory_no_product')->whereColumn('inventory_no_product.assignment_id', 'inventory_ra.id'))
                                ->whereNotExists(fn (Builder $scope) => $scope->selectRaw('1')->from('responsibility_assignment_brands as inventory_no_brand')->whereColumn('inventory_no_brand.assignment_id', 'inventory_ra.id'))
                                ->whereNotExists(fn (Builder $scope) => $scope->selectRaw('1')->from('inventory_responsibility_quantities as inventory_no_quantity')->whereColumn('inventory_no_quantity.assignment_id', 'inventory_ra.id'));
                        });
                });
        });
    }

    public function inventoryIds(User $user): Builder
    {
        return $this->applyInventories(DB::table('product_inventories'), 'product_inventories', $user)
            ->select('product_inventories.id');
    }

    public function canAccessInventory(User $user, int $inventoryId): bool
    {
        return $this->applyInventories(
            DB::table('product_inventories')->where('product_inventories.id', $inventoryId),
            'product_inventories',
            $user,
        )->exists();
    }
}
