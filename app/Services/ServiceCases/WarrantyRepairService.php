<?php

namespace App\Services\ServiceCases;

use App\Enums\DamagedStockPermission;
use App\Enums\DamagedStockSource;
use App\Enums\DamagedStockStatus;
use App\Enums\EmployeeRole;
use App\Enums\StockMovementType;
use App\Enums\WarrantyRepairPermission;
use App\Enums\WarrantyRepairSource;
use App\Enums\WarrantyRepairStatus;
use App\Exceptions\WarrantyRepairException;
use App\Models\DamagedStockEvent;
use App\Models\ProductInventory;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WarrantyRepair;
use App\Models\WarrantyRepairStatusEvent;
use App\Services\ActivityLogger;
use App\Services\Authorization\DamagedStockAuthorization;
use App\Services\Authorization\WarrantyRepairAuthorization;
use App\Services\Inventory\DamagedStockAvailabilityService;
use App\Services\Inventory\InventoryBalanceService;
use App\Services\ReferenceSequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class WarrantyRepairService
{
    public function __construct(private readonly WarrantyRepairAuthorization $auth, private readonly DamagedStockAuthorization $damagedAuth, private readonly ReferenceSequenceService $refs, private readonly ActivityLogger $activity, private readonly ServiceCaseAssigneeService $assignees, private readonly InventoryBalanceService $balances, private readonly ServiceCaseOrderContextService $orders, private readonly WarrantyRepairLifecycleService $lifecycle, private readonly DamagedStockAvailabilityService $damagedAvailability) {}

    public function create(array $data, User $actor): WarrantyRepair
    {
        $this->auth->authorize($actor, WarrantyRepairPermission::Create);
        $v = Validator::make($data, ['product_id' => 'required|integer|exists:products,id', 'warehouse_id' => 'required|integer|exists:warehouses,id', 'quantity' => 'required|integer|min:1', 'source' => ['required', Rule::enum(WarrantyRepairSource::class)], 'issue_description' => 'required|string|max:5000', 'received_at' => 'required|date|before_or_equal:now', 'expected_return_at' => 'nullable|date|after_or_equal:received_at', 'idempotency_key' => 'required|uuid', 'marketplace_platform_id' => 'nullable|integer|exists:marketplace_platforms,id', 'order_id' => 'nullable|integer|exists:orders,id', 'customer_return_id' => 'nullable|integer|exists:customer_returns,id', 'damaged_stock_event_id' => 'nullable|integer|exists:damaged_stock_events,id', 'product_inventory_id' => 'nullable|integer|exists:product_inventories,id', 'serial_number' => 'nullable|string|max:255', 'received_from' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:5000'], ['received_at.before_or_equal' => 'Received date cannot be in the future.'])->validate();
        $this->orders->assertProductBelongsToOrder(isset($v['order_id']) ? (int) $v['order_id'] : null, (int) $v['product_id'], $actor);
        $this->auth->authorize($actor, WarrantyRepairPermission::Create, new WarrantyRepair([
            'product_id' => (int) $v['product_id'],
            'warehouse_id' => (int) $v['warehouse_id'],
            'marketplace_platform_id' => isset($v['marketplace_platform_id']) ? (int) $v['marketplace_platform_id'] : null,
        ]));
        if ($existing = WarrantyRepair::query()->where('idempotency_key', $v['idempotency_key'])->first()) {
            return $existing;
        }$reference = $this->refs->nextWarrantyRepairReference();

        $case = DB::transaction(function () use ($v, $actor, $reference) {
            $case = WarrantyRepair::query()->create($v + ['reference' => $reference, 'status' => WarrantyRepairStatus::Received, 'created_by_user_id' => $actor->id]);
            $this->event($case, null, WarrantyRepairStatus::Received, null, $actor);
            $this->activity->log('warranty.created', $actor, $case, ['warranty_reference' => $case->reference, 'product_id' => $case->product_id, 'quantity' => $case->quantity]);

            return $case;
        });

        try {
            return $this->assignees->autoAssignWarranty($case, $actor);
        } catch (Throwable $exception) {
            report($exception);

            return $case->refresh();
        }
    }

    public function updateOperationalDetails(WarrantyRepair $case, array $data, User $actor): WarrantyRepair
    {
        $this->auth->authorize($actor, WarrantyRepairPermission::UpdateStatus, $case);
        $validator = Validator::make($data, ['service_provider' => 'nullable|string|max:255', 'external_service_reference' => 'nullable|string|max:255', 'expected_return_at' => 'nullable|date', 'serial_number' => 'nullable|string|max:255', 'received_from' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:5000', 'issue_description' => 'nullable|string|max:5000']);
        $validator->after(function ($validator) use ($case, $data): void {
            if (filled($data['expected_return_at'] ?? null) && CarbonImmutable::parse($data['expected_return_at'])->isBefore($case->received_at)) {
                $validator->errors()->add('expected_return_at', 'Expected Return must be on or after Received At.');
            }
        });
        $validated = $validator->validate();

        return DB::transaction(function () use ($case, $validated, $actor): WarrantyRepair {
            $locked = WarrantyRepair::query()->lockForUpdate()->findOrFail($case->id);
            $locked->forceFill($validated)->save();
            $this->activity->log('warranty.status_changed', $actor, $locked, ['warranty_reference' => $locked->reference, 'changed_fields' => array_keys($validated)]);

            return $locked->refresh();
        });
    }

    public function correctReceivedAt(WarrantyRepair $case, mixed $receivedAt, string $reason, User $actor): WarrantyRepair
    {
        $this->auth->authorize($actor, WarrantyRepairPermission::UpdateStatus, $case);
        if (! in_array($actor->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)) {
            throw new AuthorizationException;
        }

        $validated = Validator::make(
            ['received_at' => $receivedAt, 'reason' => $reason],
            [
                'received_at' => 'required|date|before_or_equal:now',
                'reason' => 'required|string|max:2000',
            ],
            ['received_at.before_or_equal' => 'Received date cannot be in the future.'],
        )->validate();

        return DB::transaction(function () use ($case, $validated, $actor): WarrantyRepair {
            $locked = WarrantyRepair::query()->lockForUpdate()->findOrFail($case->id);
            $correctedAt = CarbonImmutable::parse($validated['received_at']);
            $this->assertChronology($locked, ['received_at' => $correctedAt]);
            $oldReceivedAt = $locked->received_at;

            $locked->forceFill(['received_at' => $correctedAt])->save();
            $this->activity->log('warranty.received_at_corrected', $actor, $locked, [
                'warranty_reference' => $locked->reference,
                'old_received_at' => $oldReceivedAt?->toIso8601String(),
                'new_received_at' => $correctedAt->toIso8601String(),
                'reason' => $validated['reason'],
            ]);

            return $locked->refresh();
        });
    }

    public function createFromDamagedItem(DamagedStockEvent $damage, array $data, User $actor): WarrantyRepair
    {
        if (! $this->damagedAuth->allows($actor, DamagedStockPermission::View)) {
            throw new AuthorizationException;
        }

        $context = new WarrantyRepair([
            'product_id' => $damage->product_id,
            'warehouse_id' => $damage->warehouse_id,
            'marketplace_platform_id' => $damage->marketplace_platform_id,
        ]);
        $this->auth->authorize($actor, WarrantyRepairPermission::Create, $context);

        $validated = Validator::make($data, [
            'issue_description' => 'nullable|string|max:5000',
            'service_provider' => 'nullable|string|max:255',
            'expected_return_at' => 'nullable|date|after_or_equal:now',
            'notes' => 'nullable|string|max:5000',
            'quantity' => 'nullable|integer|min:1',
            'idempotency_key' => 'required|uuid',
        ])->validate();

        if ($existing = WarrantyRepair::query()->where('idempotency_key', $validated['idempotency_key'])->first()) {
            return $existing;
        }
        $remaining = $this->damagedAvailability->remaining($damage);
        $availableForRepair = $this->damagedAvailability->availableForRepair($damage);
        if ($remaining === 0) {
            throw new WarrantyRepairException('This damaged quantity has already been resolved and is no longer available for repair.');
        }
        $requestedQuantity = (int) ($validated['quantity'] ?? $availableForRepair);
        $this->assertRepairQuantityAvailable($requestedQuantity, $availableForRepair);

        $reference = $this->refs->nextWarrantyRepairReference();
        $case = DB::transaction(function () use ($damage, $validated, $actor, $reference, $requestedQuantity): WarrantyRepair {
            $lockedDamage = DamagedStockEvent::query()->lockForUpdate()->findOrFail($damage->id);
            if ($lockedDamage->status !== DamagedStockStatus::Damaged) {
                throw new WarrantyRepairException('Only an active damaged item can be sent to repair.');
            }
            $inventory = ProductInventory::query()->lockForUpdate()->findOrFail($lockedDamage->product_inventory_id);
            $remaining = $this->damagedAvailability->remaining($lockedDamage);
            if ($remaining === 0) {
                throw new WarrantyRepairException('This damaged quantity has already been resolved and is no longer available for repair.');
            }
            $this->assertRepairQuantityAvailable($requestedQuantity, $this->damagedAvailability->availableForRepair($lockedDamage));
            if ($inventory->product_id !== $lockedDamage->product_id || $inventory->warehouse_id !== $lockedDamage->warehouse_id || $inventory->damaged_quantity < $requestedQuantity) {
                throw new WarrantyRepairException('The damaged inventory is no longer available for this repair.');
            }

            $case = WarrantyRepair::query()->create([
                'reference' => $reference,
                'product_id' => $lockedDamage->product_id,
                'warehouse_id' => $lockedDamage->warehouse_id,
                'product_inventory_id' => $lockedDamage->product_inventory_id,
                'quantity' => $requestedQuantity,
                'source' => WarrantyRepairSource::DamagedItem,
                'damaged_stock_event_id' => $lockedDamage->id,
                'marketplace_platform_id' => $lockedDamage->marketplace_platform_id,
                'order_id' => $lockedDamage->order_id,
                'customer_return_id' => $lockedDamage->customer_return_id,
                'issue_description' => filled($validated['issue_description'] ?? null) ? $validated['issue_description'] : $lockedDamage->reason,
                'service_provider' => $validated['service_provider'] ?? null,
                'expected_return_at' => $validated['expected_return_at'] ?? null,
                'received_from' => "Damaged Items — {$lockedDamage->reference}",
                'notes' => $validated['notes'] ?? null,
                'received_at' => now(),
                'status' => WarrantyRepairStatus::Received,
                'idempotency_key' => $validated['idempotency_key'],
                'created_by_user_id' => $actor->id,
            ]);
            $this->event($case, null, WarrantyRepairStatus::Received, 'Sent to repair from Damaged Items', $actor);
            $this->activity->log('warranty.created', $actor, $case, ['warranty_reference' => $case->reference, 'damaged_stock_reference' => $lockedDamage->reference, 'product_id' => $case->product_id, 'quantity' => $case->quantity]);

            return $case;
        });

        try {
            return $this->assignees->autoAssignWarranty($case, $actor);
        } catch (Throwable $exception) {
            report($exception);

            return $case->refresh();
        }
    }

    public function createFromLegacyDamagedInventory(ProductInventory $inventory, array $data, User $actor): WarrantyRepair
    {
        if (! $this->damagedAuth->allows($actor, DamagedStockPermission::View)) {
            throw new AuthorizationException;
        }

        $context = new WarrantyRepair([
            'product_id' => $inventory->product_id,
            'warehouse_id' => $inventory->warehouse_id,
            'product_inventory_id' => $inventory->id,
        ]);
        $this->auth->authorize($actor, WarrantyRepairPermission::Create, $context);

        $validated = Validator::make($data, [
            'quantity' => 'required|integer|min:1',
            'issue_description' => 'nullable|string|max:5000',
            'service_provider' => 'nullable|string|max:255',
            'expected_return_at' => 'nullable|date|after_or_equal:now',
            'notes' => 'nullable|string|max:5000',
            'idempotency_key' => 'required|uuid',
        ])->validate();

        if ($existing = WarrantyRepair::query()->where('idempotency_key', $validated['idempotency_key'])->first()) {
            return $existing;
        }

        $available = $this->damagedAvailability->legacyAvailableForRepair($inventory);
        $this->assertLegacyRepairQuantityAvailable((int) $validated['quantity'], $available);
        $reference = $this->refs->nextWarrantyRepairReference();

        $case = DB::transaction(function () use ($inventory, $validated, $actor, $reference): WarrantyRepair {
            $lockedInventory = ProductInventory::query()->lockForUpdate()->findOrFail($inventory->id);
            $available = $this->damagedAvailability->legacyAvailableForRepair($lockedInventory);
            $this->assertLegacyRepairQuantityAvailable((int) $validated['quantity'], $available);

            $case = WarrantyRepair::query()->create([
                'reference' => $reference,
                'product_id' => $lockedInventory->product_id,
                'warehouse_id' => $lockedInventory->warehouse_id,
                'product_inventory_id' => $lockedInventory->id,
                'quantity' => (int) $validated['quantity'],
                'source' => WarrantyRepairSource::DamagedItem,
                'damaged_stock_event_id' => null,
                'marketplace_platform_id' => null,
                'order_id' => null,
                'customer_return_id' => null,
                'issue_description' => filled($validated['issue_description'] ?? null)
                    ? $validated['issue_description']
                    : 'Repair existing damaged inventory without recorded historical provenance.',
                'service_provider' => $validated['service_provider'] ?? null,
                'expected_return_at' => $validated['expected_return_at'] ?? null,
                'received_from' => 'Old Damaged Stock',
                'notes' => $validated['notes'] ?? null,
                'received_at' => now(),
                'status' => WarrantyRepairStatus::Received,
                'idempotency_key' => $validated['idempotency_key'],
                'created_by_user_id' => $actor->id,
            ]);
            $this->event($case, null, WarrantyRepairStatus::Received, 'Started from Old Damaged Stock', $actor);
            $this->activity->log('warranty.created', $actor, $case, [
                'warranty_reference' => $case->reference,
                'original_damage_source' => 'old_damaged_stock',
                'product_id' => $case->product_id,
                'quantity' => $case->quantity,
            ]);

            return $case;
        });

        try {
            return $this->assignees->autoAssignWarranty($case, $actor);
        } catch (Throwable $exception) {
            report($exception);

            return $case->refresh();
        }
    }

    public function transition(WarrantyRepair $case, WarrantyRepairStatus $to, User $actor, ?string $note = null, array $fields = []): WarrantyRepair
    {
        $permission = $to === WarrantyRepairStatus::UnderInspection ? WarrantyRepairPermission::Inspect : WarrantyRepairPermission::UpdateStatus;
        $this->auth->authorize($actor, $permission, $case);
        $repairMovementReference = $this->lifecycle->isInternalCompanyOwnedRepair($case)
            && $case->status === WarrantyRepairStatus::QcPending
            && $to === WarrantyRepairStatus::ReadyToReturn
                ? $this->refs->nextStockMovementReference()
                : null;

        return DB::transaction(function () use ($case, $to, $actor, $note, $fields, $repairMovementReference) {
            $locked = WarrantyRepair::query()->lockForUpdate()->findOrFail($case->id);
            $from = $locked->status;
            if (! $this->lifecycle->allows($locked, $to)) {
                throw new WarrantyRepairException("Cannot move Warranty / Repair from {$from->getLabel()} to {$to->getLabel()}.");
            }
            if ($to === WarrantyRepairStatus::CannotRepair && blank($note)) {
                throw new WarrantyRepairException('A reason is required when a Warranty / Repair cannot be repaired.');
            }
            $isInternal = $this->lifecycle->isInternalCompanyOwnedRepair($locked);
            $isInternalQcResult = $isInternal && $from === WarrantyRepairStatus::QcPending && in_array($to, [WarrantyRepairStatus::ReadyToReturn, WarrantyRepairStatus::CannotRepair], true);
            $effectiveTo = ($isInternal && $to === WarrantyRepairStatus::CannotRepair) || $isInternalQcResult ? WarrantyRepairStatus::Completed : $to;
            $transitionedAt = now();
            $timestamps = match ($effectiveTo) {
                WarrantyRepairStatus::InRepair => ['sent_to_technician_at' => $transitionedAt],WarrantyRepairStatus::RepairCompleted => ['repair_completed_at' => $transitionedAt],WarrantyRepairStatus::ReceivedBack => ['received_back_at' => $transitionedAt],WarrantyRepairStatus::ReadyToReturn => ['qc_at' => $transitionedAt],WarrantyRepairStatus::DispatchedBack => ['dispatched_back_at' => $this->validatedDispatchDate($locked, $fields['dispatched_back_at'] ?? $transitionedAt)],WarrantyRepairStatus::Completed => ['completed_at' => $transitionedAt],default => []
            };
            if ($isInternalQcResult) {
                $timestamps['qc_at'] = $transitionedAt;
            }
            unset($fields['dispatched_back_at']);
            $this->assertChronology($locked, [...$timestamps, ...$fields]);
            if ($repairMovementReference !== null) {
                if ($locked->repair_qc_movement_id !== null) {
                    throw new WarrantyRepairException('This repaired inventory has already been restored.');
                }
                $inventory = $this->balances->lock($locked->product_inventory_id);
                if ($inventory->damaged_quantity < $locked->quantity) {
                    throw new WarrantyRepairException('Damaged inventory is insufficient for this repair QC result.');
                }
                $movement = $this->movement($inventory, $locked, $actor, $repairMovementReference, StockMovementType::WarrantyRepairRestored, $locked->quantity, $locked->quantity, -$locked->quantity, 'Internal damaged item restored after repair QC');
                $locked->repair_qc_movement_id = $movement->id;
            }
            $locked->forceFill(['status' => $effectiveTo, ...$timestamps, ...$fields])->save();
            $this->event($locked, $from, $effectiveTo, $note, $actor);
            $this->activity->log('warranty.status_changed', $actor, $locked, ['warranty_reference' => $locked->reference, 'from_status' => $from->value, 'to_status' => $effectiveTo->value, 'requested_transition' => $to->value, 'note_present' => filled($note)]);

            return $locked->refresh();
        });
    }

    public function moveToDamaged(WarrantyRepair $case, int $quantity, int $warehouseId, string $reason, ?string $note, string $idempotencyKey, User $actor): WarrantyRepair
    {
        $this->auth->authorize($actor, WarrantyRepairPermission::MoveToDamaged, $case);
        Validator::make(compact('quantity', 'warehouseId', 'reason', 'note', 'idempotencyKey'), ['quantity' => 'required|integer|min:1', 'warehouseId' => 'required|integer|exists:warehouses,id', 'reason' => 'required|string|max:2000', 'note' => 'nullable|string|max:2000', 'idempotencyKey' => 'required|uuid'])->validate();
        if ($case->source === WarrantyRepairSource::DamagedItem) {
            throw new WarrantyRepairException('This case already represents company-owned damaged inventory.');
        }
        if ($case->status !== WarrantyRepairStatus::CannotRepair) {
            throw new WarrantyRepairException('Only a Cannot Repair case can be moved to Damaged Items.');
        }
        if ($quantity !== $case->quantity) {
            throw new WarrantyRepairException('Phase A requires the complete Warranty quantity to move together.');
        }
        $movementReference = $this->refs->nextStockMovementReference();
        $damageReference = $this->refs->nextDamagedStockReference();

        return DB::transaction(function () use ($case, $quantity, $warehouseId, $reason, $note, $idempotencyKey, $actor, $movementReference, $damageReference): WarrantyRepair {
            $locked = WarrantyRepair::query()->lockForUpdate()->findOrFail($case->id);
            if ($locked->moved_to_damaged_at !== null || DamagedStockEvent::query()->where('idempotency_key', $idempotencyKey)->exists()) {
                throw new WarrantyRepairException('This Warranty / Repair case has already moved to Damaged Items.');
            }
            if ($locked->status !== WarrantyRepairStatus::CannotRepair) {
                throw new WarrantyRepairException('Only a Cannot Repair case can be moved to Damaged Items.');
            }
            $inventory = $this->balances->lockOrCreate($locked->product_id, $warehouseId);
            $movement = $this->movement($inventory, $locked, $actor, $movementReference, StockMovementType::WarrantyRetainedDamaged, $quantity, 0, $quantity, $reason);
            $event = DamagedStockEvent::query()->create(['reference' => $damageReference, 'product_inventory_id' => $inventory->id, 'product_id' => $locked->product_id, 'warehouse_id' => $warehouseId, 'quantity' => $quantity, 'source' => DamagedStockSource::WarrantyService, 'source_type' => $locked->getMorphClass(), 'source_id' => $locked->id, 'marketplace_platform_id' => $locked->marketplace_platform_id, 'customer_return_id' => $locked->customer_return_id, 'order_id' => $locked->order_id, 'reason' => $reason, 'notes' => $note, 'occurred_at' => now(), 'reported_by_user_id' => $actor->id, 'status' => DamagedStockStatus::Damaged, 'idempotency_key' => $idempotencyKey]);
            $locked->forceFill(['moved_to_damaged_quantity' => $quantity, 'moved_to_damaged_event_id' => $event->id, 'moved_to_damaged_movement_id' => $movement->id, 'moved_to_damaged_at' => now(), 'moved_to_damaged_by_user_id' => $actor->id])->save();
            $this->activity->log('warranty.moved_to_damaged', $actor, $locked, ['warranty_reference' => $locked->reference, 'damage_reference' => $event->reference, 'quantity' => $quantity]);

            return $locked->refresh();
        });
    }

    private function movement(ProductInventory $inventory, WarrantyRepair $case, User $actor, string $reference, StockMovementType $type, int $quantity, int $availableDelta, int $damagedDelta, string $reason): StockMovement
    {
        $beforeAvailable = $inventory->available_quantity;
        $beforeDamaged = $inventory->damaged_quantity;
        $beforeReserved = $inventory->reserved_quantity;
        $inventory->forceFill(['available_quantity' => $beforeAvailable + $availableDelta, 'damaged_quantity' => $beforeDamaged + $damagedDelta])->save();

        return StockMovement::query()->create(['reference' => $reference, 'movement_group' => (string) Str::uuid(), 'product_inventory_id' => $inventory->id, 'product_id' => $inventory->product_id, 'warehouse_id' => $inventory->warehouse_id, 'movement_type' => $type, 'quantity' => $quantity, 'available_delta' => $availableDelta, 'reserved_delta' => 0, 'damaged_delta' => $damagedDelta, 'available_before' => $beforeAvailable, 'available_after' => $inventory->available_quantity, 'reserved_before' => $beforeReserved, 'reserved_after' => $inventory->reserved_quantity, 'damaged_before' => $beforeDamaged, 'damaged_after' => $inventory->damaged_quantity, 'unit_cost' => null, 'average_cost_before' => $inventory->average_cost, 'average_cost_after' => $inventory->average_cost, 'source_type' => $case->getMorphClass(), 'source_id' => $case->id, 'reason' => $reason, 'actor_user_id' => $actor->id, 'occurred_at' => now()]);
    }

    private function event(WarrantyRepair $case, ?WarrantyRepairStatus $from, WarrantyRepairStatus $to, ?string $note, User $actor): void
    {
        WarrantyRepairStatusEvent::query()->create(['warranty_repair_id' => $case->id, 'from_status' => $from, 'to_status' => $to, 'note' => $note, 'changed_by_user_id' => $actor->id, 'changed_at' => now()]);
    }

    private function validatedDispatchDate(WarrantyRepair $case, mixed $value): CarbonImmutable
    {
        $validator = Validator::make(['dispatched_back_at' => $value], ['dispatched_back_at' => 'required|date']);
        if ($validator->fails()) {
            throw new WarrantyRepairException($validator->errors()->first('dispatched_back_at'));
        }

        $dispatchedAt = CarbonImmutable::parse($value);
        if ($dispatchedAt->isFuture()) {
            throw new WarrantyRepairException('Dispatch date cannot be in the future.');
        }
        if ($dispatchedAt->isBefore($case->received_at)) {
            throw new WarrantyRepairException('Dispatch date cannot be earlier than Received At.');
        }

        $readyAt = WarrantyRepairStatusEvent::query()
            ->where('warranty_repair_id', $case->id)
            ->where('to_status', WarrantyRepairStatus::ReadyToReturn->value)
            ->value('changed_at');
        if ($readyAt !== null && $dispatchedAt->isBefore(CarbonImmutable::parse($readyAt))) {
            throw new WarrantyRepairException('Dispatch date cannot be earlier than the case becoming Ready to Return.');
        }

        return $dispatchedAt;
    }

    /** @param array<string, mixed> $overrides */
    private function assertChronology(WarrantyRepair $case, array $overrides = []): void
    {
        $fields = [
            'received_at' => 'Received At',
            'sent_to_technician_at' => 'Sent to Technician',
            'repair_completed_at' => 'Repair Completed',
            'received_back_at' => 'Received Back',
            'qc_at' => 'QC',
            'dispatched_back_at' => 'Dispatched Back',
            'completed_at' => 'Completed',
        ];
        $previous = null;
        $previousLabel = null;

        foreach ($fields as $field => $label) {
            $value = array_key_exists($field, $overrides) ? $overrides[$field] : $case->{$field};
            if ($value === null || $value === '') {
                continue;
            }

            $date = $value instanceof CarbonImmutable ? $value : CarbonImmutable::parse($value);
            if ($previous instanceof CarbonImmutable && $date->isBefore($previous)) {
                throw new WarrantyRepairException("{$label} cannot be earlier than {$previousLabel}.");
            }

            $previous = $date;
            $previousLabel = $label;
        }

        $expectedReturn = $overrides['expected_return_at'] ?? $case->expected_return_at;
        $receivedAt = $overrides['received_at'] ?? $case->received_at;
        if ($expectedReturn !== null && $receivedAt !== null && CarbonImmutable::parse($expectedReturn)->isBefore(CarbonImmutable::parse($receivedAt))) {
            throw new WarrantyRepairException('Expected Return must be on or after Received At.');
        }
    }

    private function assertRepairQuantityAvailable(int $requestedQuantity, int $availableQuantity): void
    {
        if ($availableQuantity === 0) {
            throw new WarrantyRepairException('The remaining damaged quantity is already covered by an active repair.');
        }
        if ($requestedQuantity > $availableQuantity) {
            throw new WarrantyRepairException("Only {$availableQuantity} damaged units are available for repair.");
        }
    }

    private function assertLegacyRepairQuantityAvailable(int $requestedQuantity, int $availableQuantity): void
    {
        if ($availableQuantity === 0) {
            throw new WarrantyRepairException('No Old Damaged Stock quantity is currently available for repair.');
        }
        if ($requestedQuantity > $availableQuantity) {
            throw new WarrantyRepairException("Only {$availableQuantity} Old Damaged Stock units are available for repair.");
        }
    }
}
