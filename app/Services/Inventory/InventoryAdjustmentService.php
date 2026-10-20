<?php

namespace App\Services\Inventory;

use App\DTOs\Inventory\InventoryAdjustmentData;
use App\Enums\EmployeeRole;
use App\Enums\InventoryPermission;
use App\Enums\InventoryReservationStatus;
use App\Enums\PurchasePermission;
use App\Exceptions\InventoryInvariantException;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAllocationAccount;
use App\Models\InventoryAllocationEvent;
use App\Models\InventoryAllocationReservationLine;
use App\Models\ProductInventory;
use App\Models\PurchaseReceiptItem;
use App\Models\User;
use App\Notifications\InventoryAdjustmentBatchNotification;
use App\Services\ActivityLogger;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\ReferenceSequenceService;
use App\Services\Responsibilities\WarehouseStockActionScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryAdjustmentService
{
    public function __construct(
        private readonly InventoryAuthorization $authorization,
        private readonly PurchaseAuthorization $purchaseAuthorization,
        private readonly InventoryAllocationService $allocations,
        private readonly InventoryService $inventoryService,
        private readonly ReferenceSequenceService $references,
        private readonly ActivityLogger $activity,
        private readonly WarehouseStockActionScopeService $warehouseActions,
    ) {}

    public function post(InventoryAdjustmentData $data, User $actor): InventoryAdjustment
    {
        return $this->postBatch([$data], $actor)[0];
    }

    /**
     * @param  array<int, InventoryAdjustmentData>  $lines
     * @return array<int, InventoryAdjustment>
     */
    public function postBatch(array $lines, User $actor): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Add at least one inventory adjustment.']);
        }

        $prepared = [];
        foreach ($lines as $index => $line) {
            $prepared[] = $this->prepare($line, $actor, $index);
        }
        $existing = array_map(fn (array $entry) => InventoryAdjustment::query()
            ->where('idempotency_key', $entry['data']->idempotencyKey)->first(), $prepared);
        if (count(array_filter($existing)) === count($lines)) {
            foreach ($existing as $index => $adjustment) {
                $this->assertReplay($adjustment, $prepared[$index]['data']);
            }

            return $existing;
        }
        if (count(array_filter($existing)) > 0) {
            throw ValidationException::withMessages(['lines' => 'This adjustment batch contains a previously used submission key. Reload and try again.']);
        }

        // Reference sequences are reserved before the business transaction by project convention.
        $group = (string) Str::uuid();
        foreach ($prepared as &$entry) {
            $entry['reference'] = $this->references->nextInventoryAdjustmentReference();
            $entry['movement_reference'] = $this->references->nextStockMovementReference();
            $entry['release_references'] = [];
            $count = InventoryAllocationReservationLine::query()
                ->whereHas('reservation', fn ($query) => $query->where('product_inventory_id', $entry['inventory']->id)
                    ->where('status', InventoryReservationStatus::Active->value))
                ->where('status', 'reserved')->count();
            for ($i = 0; $i < $count; $i++) {
                $entry['release_references'][] = $this->references->nextStockMovementReference();
            }
        }
        unset($entry);

        return DB::transaction(function () use ($prepared, $actor, $group): array {
            $posted = [];
            foreach ($prepared as $index => $entry) {
                $data = $entry['data'];
                if ($existing = InventoryAdjustment::query()->where('idempotency_key', $data->idempotencyKey)->lockForUpdate()->first()) {
                    throw ValidationException::withMessages(["lines.{$index}" => 'This adjustment was already posted. Reload the batch.']);
                }
                $inventory = ProductInventory::query()->lockForUpdate()->findOrFail($data->inventoryId);
                $this->authorizeAdjustment($actor, $inventory, $data);
                $receiptItem = $entry['receipt_item'];
                $availableDelta = match ($data->type) {
                    'saleable_increase', 'restore_damaged' => $data->quantity,
                    'saleable_decrease', 'mark_damaged' => -$data->quantity,
                };
                $damagedDelta = match ($data->type) {
                    'mark_damaged' => $data->quantity,
                    'restore_damaged' => -$data->quantity,
                    default => 0,
                };
                if ($inventory->available_quantity + $availableDelta < 0 || $inventory->damaged_quantity + $damagedDelta < 0) {
                    throw ValidationException::withMessages(["lines.{$index}.quantity" => 'Stock already fulfilled or consumed cannot be removed by an inventory adjustment.']);
                }
                $cost = $receiptItem?->inventory_unit_cost ?? $data->valuationUnitCost;
                if ($data->type === 'saleable_increase' && $inventory->average_cost === null && $cost === null) {
                    throw ValidationException::withMessages(["lines.{$index}.valuation_unit_cost" => 'A valuation unit cost is required for stock added to an empty inventory balance.']);
                }
                $adjustment = InventoryAdjustment::query()->create([
                    'reference' => $entry['reference'],
                    'product_inventory_id' => $inventory->id,
                    'product_id' => $inventory->product_id,
                    'warehouse_id' => $inventory->warehouse_id,
                    'type' => $data->type,
                    'available_before' => $inventory->available_quantity,
                    'available_delta' => $availableDelta,
                    'available_after' => $inventory->available_quantity + $availableDelta,
                    'damaged_before' => $inventory->damaged_quantity,
                    'damaged_delta' => $damagedDelta,
                    'damaged_after' => $inventory->damaged_quantity + $damagedDelta,
                    'reason' => trim($data->reason),
                    'performed_by_user_id' => $actor->id,
                    'performed_at' => now(),
                    'purchase_receipt_id' => $receiptItem?->purchase_receipt_id,
                    'purchase_receipt_item_id' => $receiptItem?->id,
                    'grn_reference' => $receiptItem?->receipt?->reference,
                    'reference_unit_cost' => $cost,
                    'allocation_account_id' => $data->allocationAccountId,
                    'movement_reference' => $entry['movement_reference'],
                    'movement_group' => $group,
                    'batch_key' => count($prepared) > 1 ? $group : null,
                    'idempotency_key' => $data->idempotencyKey,
                    'metadata' => $receiptItem ? ['original_grn_accepted_quantity' => $receiptItem->accepted_quantity] : null,
                    'created_at' => now(),
                ]);
                $releaseReferences = $entry['release_references'];
                try {
                    if ($availableDelta > 0) {
                        $this->inventoryService->postAdjustmentPhysical($adjustment, $inventory, $actor);
                        $this->allocations->applyAdjustment($adjustment, $inventory, $actor, $releaseReferences);
                    } else {
                        $this->allocations->applyAdjustment($adjustment, $inventory, $actor, $releaseReferences);
                        $this->inventoryService->postAdjustmentPhysical($adjustment, $inventory, $actor);
                    }
                } catch (InventoryInvariantException $exception) {
                    throw ValidationException::withMessages(["lines.{$index}.quantity" => $exception->getMessage()]);
                }
                $this->activity->log('inventory.adjusted', $actor, $adjustment, [
                    'reference' => $adjustment->reference,
                    'product_id' => $inventory->product_id,
                    'warehouse_id' => $inventory->warehouse_id,
                    'available_delta' => $availableDelta,
                    'damaged_delta' => $damagedDelta,
                    'batch_key' => $adjustment->batch_key,
                    'reason_recorded' => true,
                ]);
                $posted[] = $adjustment;
            }

            $detailsByRecipient = [];
            $allDetails = [];
            foreach ($posted as $adjustment) {
                $adjustment->loadMissing(['product:id,sku,name', 'warehouse:id,name']);
                $effectEventTypes = match ($adjustment->type) {
                    'saleable_increase' => ['inventory_adjustment_increase'],
                    'saleable_decrease' => ['inventory_adjustment_decrease'],
                    'mark_damaged' => ['damaged_allocation_hold'],
                    'restore_damaged' => ['damaged_allocation_restore', 'damaged_restore_unknown_system'],
                };
                $events = InventoryAllocationEvent::query()->with(['fromAccount.employee.user', 'toAccount.employee.user'])
                    ->where('source_type', $adjustment->getMorphClass())
                    ->where('source_id', $adjustment->id)
                    ->whereIn('event_type', $effectEventTypes)
                    ->get();
                $holderEffects = $events->map(function (InventoryAllocationEvent $event): array {
                    $account = $event->fromAccount ?? $event->toAccount;

                    return ['account' => $account, 'quantity' => $event->quantity];
                })->filter(fn (array $effect): bool => $effect['account'] instanceof InventoryAllocationAccount)
                    ->groupBy(fn (array $effect): int => $effect['account']->id)
                    ->map(fn ($effects): array => [
                        'account' => $effects->first()['account'],
                        'quantity' => (int) $effects->sum('quantity'),
                    ]);

                if ($holderEffects->isEmpty() && $adjustment->allocation_account_id !== null) {
                    $account = InventoryAllocationAccount::query()->with('employee.user')->find($adjustment->allocation_account_id);
                    if ($account !== null) {
                        $holderEffects->put($account->id, ['account' => $account, 'quantity' => abs($adjustment->available_delta)]);
                    }
                }
                if ($holderEffects->isEmpty()) {
                    $holderEffects->put(0, ['account' => null, 'quantity' => abs($adjustment->available_delta)]);
                }

                foreach ($holderEffects as $effect) {
                    /** @var InventoryAllocationAccount|null $account */
                    $account = $effect['account'];
                    $detail = [
                        'reference' => $adjustment->reference,
                        'product' => $adjustment->product?->name,
                        'sku' => $adjustment->product?->sku,
                        'warehouse' => $adjustment->warehouse?->name,
                        'original_stock_holder' => $account?->employee?->name ?? $account?->name ?? 'Unidentified ledger holder',
                        'adjustment_type' => $adjustment->type,
                        'quantity' => (int) $effect['quantity'],
                        'adjustment_quantity' => abs($adjustment->available_delta),
                        'reason' => $adjustment->reason,
                        'performed_by' => $actor->name,
                    ];
                    $allDetails[] = $detail;
                    $holderUser = $account?->employee?->status === true ? $account->employee->user : null;
                    if ($holderUser instanceof User) {
                        $detailsByRecipient[$holderUser->id]['user'] = $holderUser;
                        $detailsByRecipient[$holderUser->id]['details'][] = $detail;
                    }
                }
            }

            $admins = User::query()->where('id', '!=', $actor->id)
                ->whereHas('employee', fn ($query) => $query->where('status', true)
                    ->whereIn('role', [EmployeeRole::Owner->value, EmployeeRole::Admin->value]))->get();
            foreach ($admins as $admin) {
                $detailsByRecipient[$admin->id]['user'] = $admin;
                $detailsByRecipient[$admin->id]['details'] = $allDetails;
            }
            foreach ($detailsByRecipient as $recipient) {
                /** @var User $user */
                $user = $recipient['user'];
                $details = $recipient['details'];
                $user->notify(new InventoryAdjustmentBatchNotification(
                    array_column($details, 'reference'), $actor->name, $details,
                ));
            }

            return $posted;
        }, 5);
    }

    /** @return array{data:InventoryAdjustmentData,inventory:ProductInventory,receipt_item:?PurchaseReceiptItem} */
    private function prepare(InventoryAdjustmentData $data, User $actor, int $index): array
    {
        $validator = Validator::make([
            'inventory_id' => $data->inventoryId,
            'type' => $data->type,
            'quantity' => $data->quantity,
            'reason' => trim($data->reason),
            'idempotency_key' => $data->idempotencyKey,
            'receipt_item_id' => $data->receiptItemId,
            'allocation_account_id' => $data->allocationAccountId,
            'valuation_unit_cost' => $data->valuationUnitCost,
        ], [
            'inventory_id' => ['required', 'integer', 'exists:product_inventories,id'],
            'type' => ['required', 'in:saleable_increase,saleable_decrease,mark_damaged,restore_damaged'],
            'quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
            'receipt_item_id' => ['nullable', 'integer', 'exists:purchase_receipt_items,id'],
            'allocation_account_id' => ['nullable', 'integer', 'exists:inventory_allocation_accounts,id'],
            'valuation_unit_cost' => ['nullable', 'regex:/^\d{1,11}(?:\.\d{1,4})?$/'],
        ]);
        if ($validator->fails()) {
            throw ValidationException::withMessages(collect($validator->errors()->messages())
                ->mapWithKeys(fn (array $messages, string $field): array => ["lines.{$index}.{$field}" => $messages])->all());
        }
        if ($data->type === 'saleable_increase' && $data->isNewPurchase !== false) {
            throw ValidationException::withMessages(["lines.{$index}.is_new_purchase" => 'Choose No for a stock discrepancy. New purchases must use Quick Stock Purchase.']);
        }
        $inventory = ProductInventory::query()->findOrFail($data->inventoryId);
        $this->authorizeAdjustment($actor, $inventory, $data);
        $receiptItem = $data->receiptItemId === null ? null : PurchaseReceiptItem::query()->with('receipt')->findOrFail($data->receiptItemId);
        if ($receiptItem !== null && ($receiptItem->product_id !== $inventory->product_id || $receiptItem->receipt->warehouse_id !== $inventory->warehouse_id)) {
            throw ValidationException::withMessages(["lines.{$index}.receipt_item_id" => 'The linked GRN item does not match the selected Product and Warehouse.']);
        }
        if ($receiptItem !== null) {
            $this->purchaseAuthorization->authorize($actor, PurchasePermission::ViewReceipts, $receiptItem->receipt->purchase);
        }

        return ['data' => $data, 'inventory' => $inventory, 'receipt_item' => $receiptItem];
    }

    private function authorizeAdjustment(User $actor, ProductInventory $inventory, InventoryAdjustmentData $data): void
    {
        $employee = $actor->employee;
        $isBroad = in_array($employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true);
        $grantActionEnabled = $this->warehouseActions->allows(
            $actor, $inventory, WarehouseStockActionScopeService::ADJUST_STOCK,
        );
        $account = null;
        $isOtherHolder = false;

        if ($data->allocationAccountId !== null) {
            $accountQuery = InventoryAllocationAccount::query()->where('status', true);
            if (DB::transactionLevel() > 0) {
                $accountQuery->lockForUpdate();
            }
            $account = $accountQuery->with('employee')->findOrFail($data->allocationAccountId);
            $isOtherHolder = $account->is_system
                || ($account->employee_id !== null && $account->employee_id !== $employee?->id)
                || ($account->team_id !== null && $account->team_id !== $employee?->team_id)
                || ($account->employee_id === null && $account->team_id === null && ! $account->is_system);
        }

        if (! $isBroad && ($grantActionEnabled || $isOtherHolder)) {
            if ($data->allocationAccountId === null) {
                throw ValidationException::withMessages(['allocation_account_id' => 'Select the original stock holder for an adjustment using shared warehouse access.']);
            }
            $this->authorization->authorizeWarehouseAction(
                $actor, InventoryPermission::AdjustStock, $inventory, WarehouseStockActionScopeService::ADJUST_STOCK,
            );
            if ($isOtherHolder) {
                if ($account->employee_id === null || $account->employee?->status !== true || $account->employee?->user_id === null) {
                    throw new AuthorizationException;
                }
                if (! $this->warehouseActions->allowsHolderSelection($actor, $inventory)) {
                    throw new AuthorizationException;
                }
            }

            return;
        }

        $this->authorization->authorize($actor, InventoryPermission::AdjustStock, $inventory);
    }

    private function assertReplay(InventoryAdjustment $existing, InventoryAdjustmentData $data): void
    {
        if ($existing->product_inventory_id !== $data->inventoryId
            || $existing->type !== $data->type
            || abs($existing->available_delta) !== $data->quantity
            || $existing->allocation_account_id !== $data->allocationAccountId
            || $existing->purchase_receipt_item_id !== $data->receiptItemId
            || $existing->reason !== trim($data->reason)) {
            throw ValidationException::withMessages(['idempotency_key' => 'This submission key was already used for a different adjustment.']);
        }
    }
}
