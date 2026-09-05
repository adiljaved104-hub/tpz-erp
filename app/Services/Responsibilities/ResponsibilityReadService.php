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
    public function __construct(private readonly ResponsibilityAuthorization $authorization, private readonly PurchaseAuthorization $purchaseAuthorization) {}

    public function assignmentsFor(User $user): Builder
    {
        $query = ResponsibilityAssignment::query()->with([
            'employee.team', 'assignedBy', 'endedBy', 'brandScope.brand', 'platformScope.platform',
            'productScope.product.brandRelation', 'quantityScope.inventory.product.brandRelation', 'quantityScope.inventory.warehouse',
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

        foreach ($active as $assignment) {
            $platform = $assignment->platformScope?->platform?->name;

            if ($assignment->brandScope !== null) {
                $productIds = DB::table('products')->where('brand_id', $assignment->brandScope->product_brand_id)->pluck('id');
                foreach ($productIds as $productId) {
                    $productReasons[$productId][] = 'Brand: '.$assignment->brandScope->brand->name;
                    if ($platform !== null) {
                        $platforms[$productId][] = $platform;
                    }
                }
            }

            if ($assignment->productScope !== null) {
                $productId = $assignment->productScope->product_id;
                $productReasons[$productId][] = 'Direct Product';
                if ($platform !== null) {
                    $platforms[$productId][] = $platform;
                }
            }

            if ($assignment->quantityScope !== null) {
                $productId = $assignment->quantityScope->inventory->product_id;
                $productReasons[$productId][] = 'Quantity Assignment';
                $ownQuantities[$assignment->quantityScope->product_inventory_id] = ($ownQuantities[$assignment->quantityScope->product_inventory_id] ?? 0) + $assignment->quantityScope->assigned_quantity;
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
            ->leftJoin('product_inventories as pi', 'pi.product_id', '=', 'p.id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'pi.warehouse_id')
            ->whereIn('p.id', array_keys($productReasons))
            ->select(['p.id as product_id', 'p.name', 'p.sku', 'b.name as brand', 'pi.id as inventory_id', 'w.name as warehouse', 'pi.available_quantity', 'pi.reserved_quantity', 'pi.damaged_quantity']);

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
        $aggregate = DB::table('inventory_responsibility_quantities as irq')
            ->join('responsibility_assignments as ra', 'ra.id', '=', 'irq.assignment_id')
            ->where('ra.status', ResponsibilityAssignmentStatus::Active->value)
            ->whereIn('irq.product_inventory_id', $rows->pluck('inventory_id')->filter())
            ->select('irq.product_inventory_id')
            ->selectRaw('SUM(irq.assigned_quantity) as aggregate_assigned')
            ->groupBy('irq.product_inventory_id')->pluck('aggregate_assigned', 'product_inventory_id');

        return $rows->map(function (object $row) use ($productReasons, $platforms, $ownQuantities, $aggregate): object {
            $row->available = (int) ($row->available_quantity ?? 0);
            $row->reserved = (int) ($row->reserved_quantity ?? 0);
            $row->sellable = $row->available - $row->reserved;
            $row->damaged = (int) ($row->damaged_quantity ?? 0);
            $row->assigned_quantity = (int) ($ownQuantities[$row->inventory_id] ?? 0);
            $row->aggregate_assigned = (int) ($aggregate[$row->inventory_id] ?? 0);
            $row->remaining_assignable = $row->sellable - $row->aggregate_assigned;
            $row->capacity_status = $row->aggregate_assigned > $row->sellable ? 'Over Assigned' : ($row->aggregate_assigned === $row->sellable ? 'At Capacity' : 'OK');
            $row->visibility_reasons = array_values(array_unique($productReasons[$row->product_id]));
            $row->platforms = array_values(array_unique($platforms[$row->product_id] ?? []));

            return $row;
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
                'product' => $assignment->productScope?->product?->name ?? $assignment->quantityScope?->inventory?->product?->name,
            ]);
    }
}
