<?php

namespace App\Services\Responsibilities;

use App\Enums\PurchasePermission;
use App\Enums\ResponsibilityAssignmentStatus;
use App\Enums\ResponsibilityPermission;
use App\Models\ResponsibilityAssignment;
use App\Models\User;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\Authorization\ResponsibilityAuthorization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ResponsibilityReadService
{
    public function __construct(
        private readonly ResponsibilityAuthorization $authorization,
        private readonly PurchaseAuthorization $purchaseAuthorization,
        private readonly ResponsibilityAllocationService $allocations,
        private readonly ResponsibilityCapacityService $capacity,
    ) {}

    public function assignmentsFor(User $user): Builder
    {
        $query = ResponsibilityAssignment::query()->with([
            'employee.team', 'assignedBy', 'endedBy', 'brandScope.brand', 'platformScope.platform',
            'categoryScope.category', 'productScope.product.brandRelation', 'quantityScope.inventory.product.brandRelation', 'quantityScope.inventory.warehouse',
        ]);

        $query->addSelect([
            'physical_sellable' => DB::table('product_inventories as pi')
                ->join('inventory_responsibility_quantities as current_irq', 'current_irq.product_inventory_id', '=', 'pi.id')
                ->whereColumn('current_irq.assignment_id', 'responsibility_assignments.id')
                ->selectRaw('(pi.available_quantity - pi.reserved_quantity)')
                ->limit(1),
            'aggregate_assigned' => DB::table('inventory_responsibility_quantities as irq')
                ->join('inventory_responsibility_quantities as current_irq', 'current_irq.product_inventory_id', '=', 'irq.product_inventory_id')
                ->join('responsibility_assignments as active_ra', 'active_ra.id', '=', 'irq.assignment_id')
                ->whereColumn('current_irq.assignment_id', 'responsibility_assignments.id')
                ->where('active_ra.status', ResponsibilityAssignmentStatus::Active->value)
                ->selectRaw('COALESCE(SUM(irq.assigned_quantity), 0)'),
        ]);

        if ($this->authorization->allows($user, ResponsibilityPermission::ViewAll)) {
            return $query;
        }

        $employee = $user->employee;

        return $query->where(function (Builder $scope) use ($employee, $user): void {
            if ($this->authorization->allows($user, ResponsibilityPermission::ViewOwn)) {
                $scope->orWhere('employee_id', $employee->id);
            }

            if ($employee->team_id !== null && $this->authorization->allows($user, ResponsibilityPermission::ViewTeam)) {
                $scope->orWhereHas('employee', fn (Builder $employees) => $employees->where('team_id', $employee->team_id));
            }
        });
    }

    /** @return Collection<int, object> */
    public function myInventory(User $user): Collection
    {
        $employeeId = $user->employee?->id;

        if ($employeeId === null || ! $this->authorization->allows($user, ResponsibilityPermission::ViewOwn)) {
            return collect();
        }

        $active = $this->assignmentsFor($user)->where('employee_id', $employeeId)
            ->where('status', ResponsibilityAssignmentStatus::Active->value)->get();
        $productReasons = [];
        $platforms = [];
        $ownQuantities = [];
        $ownRemaining = [];

        foreach ($active as $assignment) {
            $platform = $assignment->platformScope?->platform?->name;
            $productIds = collect();
            $reason = null;

            if ($assignment->brandScope !== null || $assignment->categoryScope !== null) {
                $products = DB::table('products');
                if ($assignment->brandScope !== null) {
                    $products->where('brand_id', $assignment->brandScope->product_brand_id);
                }
                if ($assignment->categoryScope !== null) {
                    $products->where('category_id', $assignment->categoryScope->product_category_id);
                }

                $productIds = $products->pluck('id');
                $reason = collect([
                    $assignment->categoryScope?->category?->name ? 'Category: '.$assignment->categoryScope->category->name : null,
                    $assignment->brandScope?->brand?->name ? 'Brand: '.$assignment->brandScope->brand->name : null,
                    $platform ? 'Platform: '.$platform : null,
                ])->filter()->implode(' + ');
            }

            if ($assignment->productScope !== null) {
                $productId = $assignment->productScope->product_id;
                $productIds = collect([$productId]);
                $reason = 'Product Assignment'.($platform ? ' + Platform: '.$platform : '');
            }

            if ($assignment->quantityScope !== null) {
                $productId = $assignment->quantityScope->inventory->product_id;
                $productIds = collect([$productId]);
                $reason = 'Quantity Allocation'.($platform ? ' + Platform: '.$platform : '');
                $ownQuantities[$assignment->quantityScope->product_inventory_id] = ($ownQuantities[$assignment->quantityScope->product_inventory_id] ?? 0) + $assignment->quantityScope->assigned_quantity;
                $ownRemaining[$assignment->quantityScope->product_inventory_id] = ($ownRemaining[$assignment->quantityScope->product_inventory_id] ?? 0) + $this->allocations->usage($assignment)['remaining'];
            }

            if ($assignment->platformScope !== null && $assignment->brandScope === null && $assignment->categoryScope === null && $assignment->productScope === null && $assignment->quantityScope === null) {
                $productIds = DB::table('products')->pluck('id');
                $reason = 'Platform: '.$platform;
            }

            foreach ($productIds as $productId) {
                $productReasons[$productId][] = $reason;
                if ($platform !== null) {
                    $platforms[$productId][] = $platform;
                }
            }
        }

        if ($productReasons === []) {
            return collect();
        }

        $rowsQuery = DB::table('products as p')
            ->leftJoin('product_brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('product_categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('product_inventories as pi', 'pi.product_id', '=', 'p.id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'pi.warehouse_id')
            ->whereIn('p.id', array_keys($productReasons))
            ->select(['p.id as product_id', 'p.name', 'p.sku', 'p.model', 'b.name as brand', 'c.name as category', 'pi.id as inventory_id', 'w.id as warehouse_id', 'w.name as warehouse', 'pi.available_quantity', 'pi.reserved_quantity', 'pi.damaged_quantity']);

        if ($this->purchaseAuthorization->allows($user, PurchasePermission::ViewCostHistory)) {
            $latestCost = DB::table('purchase_receipt_items as latest_pri')
                ->join('purchase_receipts as latest_pr', 'latest_pr.id', '=', 'latest_pri.purchase_receipt_id')
                ->join('purchases as latest_p', 'latest_p.id', '=', 'latest_pr.purchase_id')
                ->select('latest_pri.inventory_unit_cost')
                ->whereColumn('latest_pri.product_id', 'p.id')
                ->whereRaw('(latest_pri.accepted_quantity + latest_pri.damaged_quantity) > 0')
                ->whereNotIn('latest_p.status', ['draft', 'cancelled'])
                ->orderByDesc('latest_pr.received_at')->orderByDesc('latest_pr.id')->orderByDesc('latest_pri.id')->limit(1);
            $rowsQuery->addSelect(['latest_purchase_cost' => $latestCost]);
        }

        $rows = $rowsQuery->get();
        $capacity = collect(array_keys($ownQuantities))
            ->mapWithKeys(fn (int $inventoryId): array => [$inventoryId => $this->capacity->summary($inventoryId)]);

        return $rows->map(function (object $row) use ($productReasons, $platforms, $ownQuantities, $ownRemaining, $capacity): object {
            $row->available = (int) ($row->available_quantity ?? 0);
            $row->reserved = (int) ($row->reserved_quantity ?? 0);
            $row->sellable = $row->available - $row->reserved;
            $row->damaged = (int) ($row->damaged_quantity ?? 0);
            $row->assigned_quantity = (int) ($ownQuantities[$row->inventory_id] ?? 0);
            $row->remaining_allocation = (int) ($ownRemaining[$row->inventory_id] ?? 0);
            $row->is_quantity_limited = $row->assigned_quantity > 0;
            $row->employee_usable = max(0, $row->is_quantity_limited ? $row->remaining_allocation : $row->sellable);
            $row->stock_status = match (true) {
                $row->employee_usable === 0 => 'out_of_stock',
                $row->employee_usable < 2 => 'low_stock',
                default => 'in_stock',
            };
            $summary = $capacity->get($row->inventory_id);
            $row->aggregate_assigned = (int) ($summary['assigned'] ?? 0);
            $row->aggregate_outstanding = (int) ($summary['outstanding'] ?? 0);
            $row->remaining_assignable = (int) ($summary['remaining'] ?? $row->sellable);
            $row->capacity_status = $summary === null ? 'OK' : $summary['status']->getLabel();
            $row->visibility_reasons = array_values(array_unique($productReasons[$row->product_id]));
            $row->platforms = array_values(array_unique($platforms[$row->product_id] ?? []));

            return $row;
        });
    }

    public function myResponsibilities(User $user): Collection
    {
        if ($user->employee === null || ! $this->authorization->allows($user, ResponsibilityPermission::ViewOwn)) {
            return collect();
        }

        return $this->assignmentsFor($user)
            ->where('employee_id', $user->employee->id)
            ->where('status', ResponsibilityAssignmentStatus::Active->value)
            ->get()
            ->map(function (ResponsibilityAssignment $assignment): array {
                $product = $assignment->productScope?->product?->name ?? $assignment->quantityScope?->inventory?->product?->name;
                $parts = collect([
                    $assignment->categoryScope?->category?->name,
                    $assignment->brandScope?->brand?->name,
                    $product,
                ])->filter()->values();
                $isQuantity = $assignment->quantityScope !== null;

                return [
                    'reference' => $assignment->reference,
                    'type' => $isQuantity ? 'Quantity Responsibility' : $this->scopeType($assignment),
                    'scope' => $parts->isEmpty() ? 'All Products' : $parts->implode(' · '),
                    'platform' => $assignment->platformScope?->platform?->name,
                    'quantity' => $isQuantity ? (int) $assignment->quantityScope->assigned_quantity : null,
                    'remaining' => $isQuantity ? $this->allocations->usage($assignment)['remaining'] : null,
                ];
            });
    }

    public function myPlatformResponsibilities(User $user): Collection
    {
        if ($user->employee === null || ! $this->authorization->allows($user, ResponsibilityPermission::ViewOwn)) {
            return collect();
        }

        return $this->assignmentsFor($user)
            ->where('employee_id', $user->employee->id)
            ->where('status', ResponsibilityAssignmentStatus::Active->value)
            ->whereHas('platformScope')
            ->get()
            ->map(fn (ResponsibilityAssignment $assignment): array => [
                'reference' => $assignment->reference,
                'platform' => $assignment->platformScope->platform->name,
                'brand' => $assignment->brandScope?->brand?->name,
                'category' => $assignment->categoryScope?->category?->name,
                'product' => $assignment->productScope?->product?->name ?? $assignment->quantityScope?->inventory?->product?->name,
            ]);
    }

    private function scopeType(ResponsibilityAssignment $assignment): string
    {
        return collect([
            $assignment->categoryScope !== null ? 'Category' : null,
            $assignment->brandScope !== null ? 'Brand' : null,
            $assignment->productScope !== null ? 'Product' : null,
            $assignment->platformScope !== null ? 'Platform' : null,
        ])->filter()->implode(' + ');
    }
}
