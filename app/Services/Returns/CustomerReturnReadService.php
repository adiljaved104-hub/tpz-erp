<?php

namespace App\Services\Returns;

use App\Enums\EmployeeRole;
use App\Enums\ResponsibilityAssignmentStatus;
use App\Models\CustomerReturn;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class CustomerReturnReadService
{
    public function query(User $user): Builder
    {
        $query = CustomerReturn::query();

        if (! in_array($user->employee?->role, [EmployeeRole::Manager, EmployeeRole::Staff], true)) {
            return $query;
        }

        $employeeId = $user->employee?->id;

        if ($employeeId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereExists(fn (QueryBuilder $item) => $item->selectRaw('1')->from('customer_return_items as return_item')->whereColumn('return_item.customer_return_id', 'customer_returns.id'))
            ->whereNotExists(function (QueryBuilder $uncovered) use ($employeeId): void {
                $uncovered->selectRaw('1')->from('customer_return_items as uncovered_return_item')
                    ->join('products as uncovered_product', 'uncovered_product.id', '=', 'uncovered_return_item.product_id')
                    ->whereColumn('uncovered_return_item.customer_return_id', 'customer_returns.id')
                    ->whereNotExists(fn (QueryBuilder $assignment) => $this->matchingAssignment($assignment, $employeeId));
            });
    }

    private function matchingAssignment(QueryBuilder $query, int $employeeId): QueryBuilder
    {
        return $query->selectRaw('1')->from('responsibility_assignments as return_ra')
            ->where('return_ra.employee_id', $employeeId)
            ->where('return_ra.status', ResponsibilityAssignmentStatus::Active->value)
            ->whereNull('return_ra.ended_at')
            ->where(function (QueryBuilder $platform): void {
                $platform->whereNotExists(fn (QueryBuilder $scope) => $scope->selectRaw('1')->from('responsibility_assignment_platforms as return_platform_none')->whereColumn('return_platform_none.assignment_id', 'return_ra.id'))
                    ->orWhereExists(fn (QueryBuilder $scope) => $scope->selectRaw('1')->from('responsibility_assignment_platforms as return_platform_match')->whereColumn('return_platform_match.assignment_id', 'return_ra.id')->whereColumn('return_platform_match.marketplace_platform_id', 'customer_returns.marketplace_platform_id'));
            })
            ->where(function (QueryBuilder $product): void {
                $product->whereExists(fn (QueryBuilder $scope) => $scope->selectRaw('1')->from('responsibility_assignment_products as return_product_scope')->whereColumn('return_product_scope.assignment_id', 'return_ra.id')->whereColumn('return_product_scope.product_id', 'uncovered_return_item.product_id'))
                    ->orWhere(function (QueryBuilder $dimensions): void {
                        $this->matchingBrandCategoryDimensions($dimensions);
                    })
                    ->orWhereExists(fn (QueryBuilder $scope) => $scope->selectRaw('1')->from('inventory_responsibility_quantities as return_quantity_scope')->join('product_inventories as return_quantity_inventory', 'return_quantity_inventory.id', '=', 'return_quantity_scope.product_inventory_id')->whereColumn('return_quantity_scope.assignment_id', 'return_ra.id')->whereColumn('return_quantity_inventory.product_id', 'uncovered_return_item.product_id')->whereColumn('return_quantity_inventory.warehouse_id', 'customer_returns.fulfillment_warehouse_id'))
                    ->orWhere(function (QueryBuilder $platformOnly): void {
                        $platformOnly->whereExists(fn (QueryBuilder $scope) => $scope->selectRaw('1')->from('responsibility_assignment_platforms as return_platform_only')->whereColumn('return_platform_only.assignment_id', 'return_ra.id'))
                            ->whereNotExists(fn (QueryBuilder $scope) => $scope->selectRaw('1')->from('responsibility_assignment_products as return_no_product')->whereColumn('return_no_product.assignment_id', 'return_ra.id'))
                            ->whereNotExists(fn (QueryBuilder $scope) => $scope->selectRaw('1')->from('responsibility_assignment_brands as return_no_brand')->whereColumn('return_no_brand.assignment_id', 'return_ra.id'))
                            ->whereNotExists(fn (QueryBuilder $scope) => $scope->selectRaw('1')->from('responsibility_assignment_categories as return_no_category')->whereColumn('return_no_category.assignment_id', 'return_ra.id'))
                            ->whereNotExists(fn (QueryBuilder $scope) => $scope->selectRaw('1')->from('inventory_responsibility_quantities as return_no_quantity')->whereColumn('return_no_quantity.assignment_id', 'return_ra.id'));
                    });
            });
    }

    private function matchingBrandCategoryDimensions(QueryBuilder $query): void
    {
        $query->where(function (QueryBuilder $present): void {
            $present->whereExists(fn (QueryBuilder $scope) => $scope->selectRaw('1')->from('responsibility_assignment_brands as return_brand_present')->whereColumn('return_brand_present.assignment_id', 'return_ra.id'))
                ->orWhereExists(fn (QueryBuilder $scope) => $scope->selectRaw('1')->from('responsibility_assignment_categories as return_category_present')->whereColumn('return_category_present.assignment_id', 'return_ra.id'));
        })->where(function (QueryBuilder $brand): void {
            $brand->whereNotExists(fn (QueryBuilder $scope) => $scope->selectRaw('1')->from('responsibility_assignment_brands as return_brand_none')->whereColumn('return_brand_none.assignment_id', 'return_ra.id'))
                ->orWhereExists(fn (QueryBuilder $scope) => $scope->selectRaw('1')->from('responsibility_assignment_brands as return_brand_match')->whereColumn('return_brand_match.assignment_id', 'return_ra.id')->whereColumn('return_brand_match.product_brand_id', 'uncovered_product.brand_id'));
        })->where(function (QueryBuilder $category): void {
            $category->whereNotExists(fn (QueryBuilder $scope) => $scope->selectRaw('1')->from('responsibility_assignment_categories as return_category_none')->whereColumn('return_category_none.assignment_id', 'return_ra.id'))
                ->orWhereExists(fn (QueryBuilder $scope) => $scope->selectRaw('1')->from('responsibility_assignment_categories as return_category_match')->whereColumn('return_category_match.assignment_id', 'return_ra.id')->whereColumn('return_category_match.product_category_id', 'uncovered_product.category_id'));
        });
    }
}
