<?php

namespace App\Services\Responsibilities;

use App\Enums\ResponsibilityAssignmentStatus;
use App\Enums\ResponsibilityCapacityStatus;
use App\Exceptions\ResponsibilityCapacityExceededException;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use Illuminate\Support\Facades\DB;

class ResponsibilityCapacityService
{
    /** @return array{inventory: ProductInventory, assigned: int, sellable: int, remaining: int, status: ResponsibilityCapacityStatus} */
    public function lockAndAssert(int $inventoryId, int $proposedQuantity, ?int $excludeAssignmentId = null): array
    {
        $inventory = ProductInventory::query()
            ->select(['id', 'product_id', 'warehouse_id', 'available_quantity', 'reserved_quantity', 'damaged_quantity'])
            ->lockForUpdate()
            ->findOrFail($inventoryId);

        $assignments = ResponsibilityAssignment::query()
            ->select('responsibility_assignments.id')
            ->join('inventory_responsibility_quantities as irq', 'irq.assignment_id', '=', 'responsibility_assignments.id')
            ->where('irq.product_inventory_id', $inventoryId)
            ->where('responsibility_assignments.status', ResponsibilityAssignmentStatus::Active->value)
            ->when($excludeAssignmentId !== null, fn ($query) => $query->where('responsibility_assignments.id', '!=', $excludeAssignmentId))
            ->orderBy('responsibility_assignments.id')
            ->lockForUpdate()
            ->get();

        $assigned = (int) DB::table('inventory_responsibility_quantities')
            ->whereIn('assignment_id', $assignments->pluck('id'))
            ->sum('assigned_quantity');
        $sellable = $inventory->sellableQuantity();

        if ($assigned + $proposedQuantity > $sellable) {
            throw new ResponsibilityCapacityExceededException("Only {$sellable} sellable units exist; {$assigned} are already assigned.");
        }

        return $this->result($inventory, $assigned + $proposedQuantity);
    }

    /** @return array{inventory: ProductInventory, assigned: int, sellable: int, remaining: int, status: ResponsibilityCapacityStatus} */
    public function summary(int $inventoryId): array
    {
        $inventory = ProductInventory::query()
            ->select(['id', 'product_id', 'warehouse_id', 'available_quantity', 'reserved_quantity', 'damaged_quantity'])
            ->findOrFail($inventoryId);
        $assigned = (int) DB::table('inventory_responsibility_quantities as irq')
            ->join('responsibility_assignments as ra', 'ra.id', '=', 'irq.assignment_id')
            ->where('irq.product_inventory_id', $inventoryId)
            ->where('ra.status', ResponsibilityAssignmentStatus::Active->value)
            ->sum('irq.assigned_quantity');

        return $this->result($inventory, $assigned);
    }

    /** @return array{inventory: ProductInventory, assigned: int, sellable: int, remaining: int, status: ResponsibilityCapacityStatus} */
    private function result(ProductInventory $inventory, int $assigned): array
    {
        $sellable = $inventory->sellableQuantity();
        $status = match (true) {
            $assigned > $sellable => ResponsibilityCapacityStatus::OverAssigned,
            $assigned === $sellable => ResponsibilityCapacityStatus::AtCapacity,
            default => ResponsibilityCapacityStatus::Ok,
        };

        return [
            'inventory' => $inventory,
            'assigned' => $assigned,
            'sellable' => $sellable,
            'remaining' => $sellable - $assigned,
            'status' => $status,
        ];
    }
}
