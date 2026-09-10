<?php

namespace App\Services\Responsibilities;

use App\Enums\ResponsibilityAssignmentStatus;
use App\Enums\ResponsibilityCapacityStatus;
use App\Exceptions\ResponsibilityCapacityExceededException;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;

class ResponsibilityCapacityService
{
    public function __construct(private readonly ResponsibilityAllocationService $allocations) {}

    /** @return array{inventory: ProductInventory, assigned: int, outstanding: int, sellable: int, remaining: int, status: ResponsibilityCapacityStatus} */
    public function lockAndAssert(int $inventoryId, int $proposedQuantity, ?int $excludeAssignmentId = null): array
    {
        $inventory = ProductInventory::query()
            ->select(['id', 'product_id', 'warehouse_id', 'available_quantity', 'reserved_quantity', 'damaged_quantity'])
            ->lockForUpdate()
            ->findOrFail($inventoryId);

        $assignments = ResponsibilityAssignment::query()
            ->select('responsibility_assignments.*')
            ->join('inventory_responsibility_quantities as irq', 'irq.assignment_id', '=', 'responsibility_assignments.id')
            ->where('irq.product_inventory_id', $inventoryId)
            ->where('responsibility_assignments.status', ResponsibilityAssignmentStatus::Active->value)
            ->when($excludeAssignmentId !== null, fn ($query) => $query->where('responsibility_assignments.id', '!=', $excludeAssignmentId))
            ->orderBy('responsibility_assignments.id')
            ->lockForUpdate()
            ->with('quantityScope')->get();

        $assigned = (int) $assignments->sum(fn (ResponsibilityAssignment $assignment): int => (int) $assignment->quantityScope->assigned_quantity);
        $outstanding = (int) $assignments->sum(fn (ResponsibilityAssignment $assignment): int => $this->allocations->usage($assignment, lock: true)['remaining']);
        $sellable = $inventory->sellableQuantity();

        if ($outstanding + $proposedQuantity > $sellable) {
            throw new ResponsibilityCapacityExceededException("Only {$sellable} sellable units exist; {$outstanding} remain allocated.");
        }

        return $this->result($inventory, $assigned + $proposedQuantity, $outstanding + $proposedQuantity);
    }

    /** @return array{inventory: ProductInventory, assigned: int, outstanding: int, sellable: int, remaining: int, status: ResponsibilityCapacityStatus} */
    public function summary(int $inventoryId): array
    {
        $inventory = ProductInventory::query()
            ->select(['id', 'product_id', 'warehouse_id', 'available_quantity', 'reserved_quantity', 'damaged_quantity'])
            ->findOrFail($inventoryId);
        $assignments = ResponsibilityAssignment::query()
            ->select('responsibility_assignments.*')
            ->join('inventory_responsibility_quantities as irq', 'irq.assignment_id', '=', 'responsibility_assignments.id')
            ->where('irq.product_inventory_id', $inventoryId)
            ->where('responsibility_assignments.status', ResponsibilityAssignmentStatus::Active->value)
            ->with('quantityScope')->get();
        $assigned = (int) $assignments->sum(fn (ResponsibilityAssignment $assignment): int => (int) $assignment->quantityScope->assigned_quantity);
        $outstanding = (int) $assignments->sum(fn (ResponsibilityAssignment $assignment): int => $this->allocations->usage($assignment)['remaining']);

        return $this->result($inventory, $assigned, $outstanding);
    }

    /** @return array{inventory: ProductInventory, assigned: int, outstanding: int, sellable: int, remaining: int, status: ResponsibilityCapacityStatus} */
    private function result(ProductInventory $inventory, int $assigned, int $outstanding): array
    {
        $sellable = $inventory->sellableQuantity();
        $status = match (true) {
            $outstanding > $sellable => ResponsibilityCapacityStatus::OverAssigned,
            $outstanding === $sellable => ResponsibilityCapacityStatus::AtCapacity,
            default => ResponsibilityCapacityStatus::Ok,
        };

        return [
            'inventory' => $inventory,
            'assigned' => $assigned,
            'outstanding' => $outstanding,
            'sellable' => $sellable,
            'remaining' => $sellable - $outstanding,
            'status' => $status,
        ];
    }
}
