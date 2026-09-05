<?php

namespace App\Services\Orders;

use App\Enums\EmployeeRole;
use App\Enums\ResponsibilityAssignmentStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class OrderResponsibilityScopeService
{
    public function requiresScope(User $user): bool
    {
        return in_array($user->employee?->role, [EmployeeRole::Manager, EmployeeRole::Staff], true);
    }

    public function applyProducts(Builder $query, User $user, ?int $platformId, int $warehouseId): Builder
    {
        if (! $this->requiresScope($user)) {
            return $query;
        }

        $employeeId = $user->employee?->id;

        if ($employeeId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereExists(fn (QueryBuilder $assignment) => $this->matchingAssignment(
            $assignment,
            $employeeId,
            'products.id',
            'products.brand_id',
            $platformId,
            $warehouseId,
        ));
    }

    public function canAccessProduct(User $user, int $productId, ?int $platformId, int $warehouseId): bool
    {
        return $this->applyProducts(Product::query()->whereKey($productId), $user, $platformId, $warehouseId)->exists();
    }

    public function hasMatchingActiveResponsibility(User $user, int $productId, ?int $platformId, int $warehouseId): bool
    {
        $employeeId = $user->employee?->id;
        if ($employeeId === null) {
            return false;
        }

        return Product::query()->whereKey($productId)
            ->whereExists(fn (QueryBuilder $assignment) => $this->matchingAssignment(
                $assignment,
                $employeeId,
                'products.id',
                'products.brand_id',
                $platformId,
                $warehouseId,
            ))->exists();
    }

    public function canAccessOrder(User $user, Order $order): bool
    {
        if (! $this->requiresScope($user)) {
            return true;
        }

        $productIds = $order->items()->pluck('product_id');

        return $productIds->isNotEmpty() && $productIds->every(fn (int $productId): bool => $this->canAccessProduct(
            $user,
            $productId,
            $order->marketplace_platform_id,
            $order->warehouse_id,
        ));
    }

    public function applyOrders(Builder $query, User $user): Builder
    {
        if (! $this->requiresScope($user)) {
            return $query;
        }

        $employeeId = $user->employee?->id;
        if ($employeeId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereNotExists(function (QueryBuilder $uncovered) use ($employeeId): void {
            $uncovered->selectRaw('1')
                ->from('order_items as scoped_order_items')
                ->join('products as scoped_order_products', 'scoped_order_products.id', '=', 'scoped_order_items.product_id')
                ->whereColumn('scoped_order_items.order_id', 'orders.id')
                ->whereNotExists(fn (QueryBuilder $assignment) => $this->matchingAssignmentByColumns(
                    $assignment,
                    $employeeId,
                    'scoped_order_products.id',
                    'scoped_order_products.brand_id',
                    'orders.marketplace_platform_id',
                    'orders.warehouse_id',
                ));
        });
    }

    public function applyMarketplaceRemovals(Builder $query, User $user): Builder
    {
        if (! $this->requiresScope($user)) {
            return $query;
        }

        $employeeId = $user->employee?->id;
        if ($employeeId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereNotExists(function (QueryBuilder $uncovered) use ($employeeId): void {
            $uncovered->selectRaw('1')
                ->from('marketplace_return_removal_items as scoped_removal_items')
                ->join('products as scoped_removal_products', 'scoped_removal_products.id', '=', 'scoped_removal_items.product_id')
                ->whereColumn('scoped_removal_items.marketplace_return_removal_id', 'marketplace_return_removals.id')
                ->whereNotExists(fn (QueryBuilder $assignment) => $this->matchingAssignmentByColumns(
                    $assignment,
                    $employeeId,
                    'scoped_removal_products.id',
                    'scoped_removal_products.brand_id',
                    'marketplace_return_removals.marketplace_platform_id',
                    'marketplace_return_removals.source_warehouse_id',
                ));
        });
    }

    public function applyWarrantyCases(Builder $query, User $user): Builder
    {
        if (! $this->requiresScope($user)) {
            return $query;
        }

        $employeeId = $user->employee?->id;
        if ($employeeId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereExists(function (QueryBuilder $product) use ($employeeId): void {
            $product->selectRaw('1')
                ->from('products as scoped_case_products')
                ->whereColumn('scoped_case_products.id', 'warranty_repairs.product_id')
                ->whereExists(fn (QueryBuilder $assignment) => $this->matchingAssignmentByColumns(
                    $assignment,
                    $employeeId,
                    'scoped_case_products.id',
                    'scoped_case_products.brand_id',
                    'warranty_repairs.marketplace_platform_id',
                    'warranty_repairs.warehouse_id',
                ));
        });
    }

    public function applySafetClaims(Builder $query, User $user): Builder
    {
        if (! $this->requiresScope($user)) {
            return $query;
        }

        $employeeId = $user->employee?->id;
        if ($employeeId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereExists(function (QueryBuilder $product) use ($employeeId): void {
            $product->selectRaw('1')
                ->from('products as scoped_claim_products')
                ->join('damaged_stock_events as scoped_claim_damage', 'scoped_claim_damage.id', '=', 'safet_claims.damaged_stock_event_id')
                ->whereColumn('scoped_claim_products.id', 'safet_claims.product_id')
                ->whereExists(fn (QueryBuilder $assignment) => $this->matchingAssignmentByColumns(
                    $assignment,
                    $employeeId,
                    'scoped_claim_products.id',
                    'scoped_claim_products.brand_id',
                    'safet_claims.marketplace_platform_id',
                    'scoped_claim_damage.warehouse_id',
                ));
        });
    }

    public function applyComplaintCases(Builder $query, User $user): Builder
    {
        if (! $this->requiresScope($user)) {
            return $query;
        }

        $employeeId = $user->employee?->id;
        if ($employeeId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereExists(function (QueryBuilder $order) use ($employeeId): void {
            $order->selectRaw('1')
                ->from('orders as scoped_case_orders')
                ->whereColumn('scoped_case_orders.id', 'complaints.order_id')
                ->whereExists(function (QueryBuilder $product) use ($employeeId): void {
                    $product->selectRaw('1')
                        ->from('products as scoped_case_products')
                        ->whereColumn('scoped_case_products.id', 'complaints.product_id')
                        ->whereExists(fn (QueryBuilder $assignment) => $this->matchingAssignmentByColumns(
                            $assignment,
                            $employeeId,
                            'scoped_case_products.id',
                            'scoped_case_products.brand_id',
                            'complaints.marketplace_platform_id',
                            'scoped_case_orders.warehouse_id',
                        ));
                });
        });
    }

    private function matchingAssignmentByColumns(QueryBuilder $query, int $employeeId, string $productColumn, string $brandColumn, string $platformColumn, string $warehouseColumn): QueryBuilder
    {
        $query->selectRaw('1')
            ->from('responsibility_assignments as order_ra')
            ->where('order_ra.employee_id', $employeeId)
            ->where('order_ra.status', ResponsibilityAssignmentStatus::Active->value)
            ->whereNull('order_ra.ended_at')
            ->where(function (QueryBuilder $platform) use ($platformColumn): void {
                $platform->whereNotExists(function (QueryBuilder $scope): void {
                    $scope->selectRaw('1')->from('responsibility_assignment_platforms as order_platform_scope')
                        ->whereColumn('order_platform_scope.assignment_id', 'order_ra.id');
                })->orWhereExists(function (QueryBuilder $scope) use ($platformColumn): void {
                    $scope->selectRaw('1')->from('responsibility_assignment_platforms as order_platform_match')
                        ->whereColumn('order_platform_match.assignment_id', 'order_ra.id')
                        ->whereColumn('order_platform_match.marketplace_platform_id', $platformColumn);
                });
            })
            ->where(function (QueryBuilder $product) use ($productColumn, $brandColumn, $warehouseColumn): void {
                $product->whereExists(function (QueryBuilder $scope) use ($productColumn): void {
                    $scope->selectRaw('1')->from('responsibility_assignment_products as order_product_scope')
                        ->whereColumn('order_product_scope.assignment_id', 'order_ra.id')
                        ->whereColumn('order_product_scope.product_id', $productColumn);
                })->orWhereExists(function (QueryBuilder $scope) use ($brandColumn): void {
                    $scope->selectRaw('1')->from('responsibility_assignment_brands as order_brand_scope')
                        ->whereColumn('order_brand_scope.assignment_id', 'order_ra.id')
                        ->whereColumn('order_brand_scope.product_brand_id', $brandColumn);
                })->orWhereExists(function (QueryBuilder $scope) use ($productColumn, $warehouseColumn): void {
                    $scope->selectRaw('1')->from('inventory_responsibility_quantities as order_quantity_scope')
                        ->join('product_inventories as order_quantity_inventory', 'order_quantity_inventory.id', '=', 'order_quantity_scope.product_inventory_id')
                        ->whereColumn('order_quantity_scope.assignment_id', 'order_ra.id')
                        ->whereColumn('order_quantity_inventory.product_id', $productColumn)
                        ->whereColumn('order_quantity_inventory.warehouse_id', $warehouseColumn);
                })->orWhere(function (QueryBuilder $platformOnly): void {
                    $platformOnly->whereExists(function (QueryBuilder $scope): void {
                        $scope->selectRaw('1')->from('responsibility_assignment_platforms as order_platform_only')
                            ->whereColumn('order_platform_only.assignment_id', 'order_ra.id');
                    })->whereNotExists(function (QueryBuilder $scope): void {
                        $scope->selectRaw('1')->from('responsibility_assignment_products as order_no_product')
                            ->whereColumn('order_no_product.assignment_id', 'order_ra.id');
                    })->whereNotExists(function (QueryBuilder $scope): void {
                        $scope->selectRaw('1')->from('responsibility_assignment_brands as order_no_brand')
                            ->whereColumn('order_no_brand.assignment_id', 'order_ra.id');
                    })->whereNotExists(function (QueryBuilder $scope): void {
                        $scope->selectRaw('1')->from('inventory_responsibility_quantities as order_no_quantity')
                            ->whereColumn('order_no_quantity.assignment_id', 'order_ra.id');
                    });
                });
            });

        return $query;
    }

    private function matchingAssignment(QueryBuilder $query, int $employeeId, string $productColumn, string $brandColumn, string|int|null $platformId, int $warehouseId): QueryBuilder
    {
        $query->selectRaw('1')
            ->from('responsibility_assignments as order_ra')
            ->where('order_ra.employee_id', $employeeId)
            ->where('order_ra.status', ResponsibilityAssignmentStatus::Active->value)
            ->whereNull('order_ra.ended_at')
            ->where(function (QueryBuilder $platform) use ($platformId): void {
                $platform->whereNotExists(function (QueryBuilder $scope): void {
                    $scope->selectRaw('1')->from('responsibility_assignment_platforms as order_platform_scope')
                        ->whereColumn('order_platform_scope.assignment_id', 'order_ra.id');
                });

                if ($platformId !== null) {
                    $platform->orWhereExists(function (QueryBuilder $scope) use ($platformId): void {
                        $scope->selectRaw('1')->from('responsibility_assignment_platforms as order_platform_match')
                            ->whereColumn('order_platform_match.assignment_id', 'order_ra.id')
                            ->where('order_platform_match.marketplace_platform_id', $platformId);
                    });
                }
            })
            ->where(function (QueryBuilder $product) use ($productColumn, $brandColumn, $warehouseId): void {
                $product->whereExists(function (QueryBuilder $scope) use ($productColumn): void {
                    $scope->selectRaw('1')->from('responsibility_assignment_products as order_product_scope')
                        ->whereColumn('order_product_scope.assignment_id', 'order_ra.id')
                        ->whereColumn('order_product_scope.product_id', $productColumn);
                })->orWhereExists(function (QueryBuilder $scope) use ($brandColumn): void {
                    $scope->selectRaw('1')->from('responsibility_assignment_brands as order_brand_scope')
                        ->whereColumn('order_brand_scope.assignment_id', 'order_ra.id')
                        ->whereColumn('order_brand_scope.product_brand_id', $brandColumn);
                })->orWhereExists(function (QueryBuilder $scope) use ($productColumn, $warehouseId): void {
                    $scope->selectRaw('1')->from('inventory_responsibility_quantities as order_quantity_scope')
                        ->join('product_inventories as order_quantity_inventory', 'order_quantity_inventory.id', '=', 'order_quantity_scope.product_inventory_id')
                        ->whereColumn('order_quantity_scope.assignment_id', 'order_ra.id')
                        ->whereColumn('order_quantity_inventory.product_id', $productColumn)
                        ->where('order_quantity_inventory.warehouse_id', $warehouseId);
                })->orWhere(function (QueryBuilder $platformOnly): void {
                    $platformOnly->whereExists(function (QueryBuilder $scope): void {
                        $scope->selectRaw('1')->from('responsibility_assignment_platforms as order_platform_only')
                            ->whereColumn('order_platform_only.assignment_id', 'order_ra.id');
                    })->whereNotExists(function (QueryBuilder $scope): void {
                        $scope->selectRaw('1')->from('responsibility_assignment_products as order_no_product')
                            ->whereColumn('order_no_product.assignment_id', 'order_ra.id');
                    })->whereNotExists(function (QueryBuilder $scope): void {
                        $scope->selectRaw('1')->from('responsibility_assignment_brands as order_no_brand')
                            ->whereColumn('order_no_brand.assignment_id', 'order_ra.id');
                    })->whereNotExists(function (QueryBuilder $scope): void {
                        $scope->selectRaw('1')->from('inventory_responsibility_quantities as order_no_quantity')
                            ->whereColumn('order_no_quantity.assignment_id', 'order_ra.id');
                    });
                });
            });

        return $query;
    }
}
