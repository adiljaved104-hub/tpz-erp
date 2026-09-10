<?php

namespace App\Services\Responsibilities;

use App\Enums\InventoryReservationStatus;
use App\Models\InventoryReservation;
use App\Models\OrderFulfillmentItem;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\User;
use App\Services\Orders\OrderResponsibilityScopeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResponsibilityAllocationService
{
    public function __construct(private readonly OrderResponsibilityScopeService $scope) {}

    /**
     * Lock and validate the exact quantity allocation that applies to a reservation.
     *
     * @return array{assignment_id:int,assigned:int,active:int,fulfilled:int,restored:int,consumed:int,remaining:int}|null
     */
    public function lockAndAssert(User $actor, ProductInventory $inventory, ?int $platformId, int $requested): ?array
    {
        if (! $this->scope->requiresScope($actor)) {
            return null;
        }

        $inventory = ProductInventory::query()->lockForUpdate()->findOrFail($inventory->id);

        $employeeId = $actor->employee?->id;
        if ($employeeId === null) {
            throw ValidationException::withMessages(['responsibility' => 'An active Employee profile is required.']);
        }

        $assignments = ResponsibilityAssignment::query()
            ->active()
            ->where('employee_id', $employeeId)
            ->whereHas('quantityScope', fn ($query) => $query->where('product_inventory_id', $inventory->id))
            ->where(function ($query) use ($platformId): void {
                $query->whereDoesntHave('platformScope');
                if ($platformId !== null) {
                    $query->orWhereHas('platformScope', fn ($platform) => $platform->where('marketplace_platform_id', $platformId));
                }
            })
            ->with('quantityScope')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($assignments->count() > 1) {
            throw ValidationException::withMessages([
                'responsibility' => 'Multiple quantity allocations apply to this Product and Platform. End the overlapping allocation before reserving stock.',
            ]);
        }
        if ($assignments->isEmpty()) {
            return null;
        }

        $usage = $this->usage($assignments->sole(), lock: true);
        if ($requested > $usage['remaining']) {
            throw ValidationException::withMessages([
                'quantity' => "The requested quantity exceeds this Employee's remaining allocation of {$usage['remaining']}.",
            ]);
        }

        return $usage;
    }

    /** @return array{assignment_id:int,assigned:int,active:int,fulfilled:int,restored:int,consumed:int,remaining:int} */
    public function usage(ResponsibilityAssignment $assignment, bool $lock = false): array
    {
        $assignment->loadMissing('quantityScope');
        $assigned = (int) ($assignment->quantityScope?->assigned_quantity ?? 0);
        $reservations = DB::table('inventory_reservations as reservation')
            ->join('responsibility_inventory_consumptions as consumption', 'consumption.inventory_reservation_id', '=', 'reservation.id')
            ->where('consumption.responsibility_assignment_id', $assignment->id)
            ->whereIn('status', [InventoryReservationStatus::Active->value, InventoryReservationStatus::Fulfilled->value])
            ->orderBy('reservation.id');
        if ($lock) {
            $reservations->lockForUpdate();
        }
        $reservations = $reservations->get(['reservation.id', 'reservation.order_item_id', 'reservation.quantity', 'reservation.status']);
        $active = (int) $reservations->where('status', InventoryReservationStatus::Active->value)->sum('quantity');
        $fulfilledRows = $reservations->where('status', InventoryReservationStatus::Fulfilled->value);
        $directFulfilments = DB::table('order_fulfillment_items as fulfillment')
            ->join('responsibility_inventory_consumptions as consumption', 'consumption.order_fulfillment_item_id', '=', 'fulfillment.id')
            ->where('consumption.responsibility_assignment_id', $assignment->id)
            ->get(['fulfillment.order_item_id', 'fulfillment.quantity']);
        $fulfilled = (int) $fulfilledRows->sum('quantity') + (int) $directFulfilments->sum('quantity');
        $orderItemIds = $fulfilledRows->pluck('order_item_id')->merge($directFulfilments->pluck('order_item_id'))->filter()->unique()->values();
        $restored = $orderItemIds->isEmpty() ? 0 : $this->sellableReturns($orderItemIds->all());
        $restored = min($fulfilled, $restored);
        $consumed = $active + max(0, $fulfilled - $restored);

        return [
            'assignment_id' => $assignment->id,
            'assigned' => $assigned,
            'active' => $active,
            'fulfilled' => $fulfilled,
            'restored' => $restored,
            'consumed' => $consumed,
            'remaining' => max(0, $assigned - $consumed),
        ];
    }

    public function assertMaySupersede(ResponsibilityAssignment $assignment): void
    {
        $usage = $this->usage($assignment, lock: true);
        if ($usage['consumed'] > 0) {
            throw ValidationException::withMessages([
                'assignment' => 'This quantity assignment has active or fulfilled consumption. Release active reservations or create a separate future allocation instead.',
            ]);
        }
    }

    public function recordReservation(int $assignmentId, InventoryReservation $reservation): void
    {
        $this->record($assignmentId, $reservation->id, null);
    }

    public function recordFulfilment(int $assignmentId, OrderFulfillmentItem $fulfilment): void
    {
        $this->record($assignmentId, null, $fulfilment->id);
    }

    public function assertMayDeactivate(ResponsibilityAssignment $assignment): void
    {
        $usage = $this->usage($assignment, lock: true);
        if ($usage['active'] > 0) {
            throw ValidationException::withMessages([
                'assignment' => 'Release active reservations before deactivating this quantity assignment.',
            ]);
        }
    }

    /** @param array<int, int> $orderItemIds */
    private function sellableReturns(array $orderItemIds): int
    {
        $direct = (int) DB::table('customer_return_inspections as inspection')
            ->join('customer_return_items as return_item', 'return_item.id', '=', 'inspection.customer_return_item_id')
            ->whereIn('return_item.order_item_id', $orderItemIds)
            ->where('inspection.result', 'sellable')
            ->sum('inspection.quantity');
        $marketplace = (int) DB::table('customer_return_marketplace_dispositions as disposition')
            ->join('customer_return_items as return_item', 'return_item.id', '=', 'disposition.customer_return_item_id')
            ->whereIn('return_item.order_item_id', $orderItemIds)
            ->where('disposition.result', 'sellable')
            ->sum('disposition.quantity');
        $removed = (int) DB::table('customer_return_inspections as inspection')
            ->join('marketplace_return_removal_items as removal_item', 'removal_item.id', '=', 'inspection.marketplace_return_removal_item_id')
            ->join('customer_return_items as return_item', 'return_item.id', '=', 'removal_item.customer_return_item_id')
            ->whereIn('return_item.order_item_id', $orderItemIds)
            ->where('inspection.result', 'sellable')
            ->sum('inspection.quantity');

        return $direct + $marketplace + $removed;
    }

    private function record(int $assignmentId, ?int $reservationId, ?int $fulfilmentItemId): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Responsibility allocation attribution requires an active transaction.');
        }

        DB::table('responsibility_inventory_consumptions')->insert([
            'responsibility_assignment_id' => $assignmentId,
            'inventory_reservation_id' => $reservationId,
            'order_fulfillment_item_id' => $fulfilmentItemId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
