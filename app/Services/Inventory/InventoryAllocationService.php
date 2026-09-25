<?php

namespace App\Services\Inventory;

use App\Enums\InventoryAllocationMode;
use App\Exceptions\InventoryInvariantException;
use App\Models\Employee;
use App\Models\InventoryAllocationAccount;
use App\Models\InventoryAllocationBalance;
use App\Models\InventoryAllocationEvent;
use App\Models\InventoryAllocationReservationLine;
use App\Models\InventoryAllocationTransitionLine;
use App\Models\InventoryReservation;
use App\Models\OrderItem;
use App\Models\ProductInventory;
use App\Models\PurchaseReceiptAllocationLine;
use App\Models\PurchaseReceiptItem;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryAllocationService
{
    public function __construct(private readonly InventoryAllocationPolicyService $policy) {}

    public function systemAccount(): InventoryAllocationAccount
    {
        return InventoryAllocationAccount::query()->firstOrCreate(['identity_key' => 'system'], [
            'type' => 'system', 'name' => 'System / Unallocated', 'is_system' => true, 'status' => true,
        ]);
    }

    public function employeeAccount(int $employeeId): InventoryAllocationAccount
    {
        $name = Employee::query()->whereKey($employeeId)->value('name') ?? "Employee #{$employeeId}";

        return InventoryAllocationAccount::query()->firstOrCreate(['identity_key' => "employee:{$employeeId}"], [
            'employee_id' => $employeeId, 'type' => 'employee', 'name' => $name, 'is_system' => false, 'status' => true,
        ]);
    }

    public function teamAccount(int $teamId): InventoryAllocationAccount
    {
        $name = Team::query()->whereKey($teamId)->value('name') ?? "Team #{$teamId}";

        return InventoryAllocationAccount::query()->firstOrCreate(['identity_key' => "team:{$teamId}"], [
            'team_id' => $teamId, 'type' => 'team', 'name' => $name, 'is_system' => false, 'status' => true,
        ]);
    }

    public function recordReceipt(PurchaseReceiptItem $item, ProductInventory $inventory, User $actor, ?int $selectedAccountId): void
    {
        $quantity = $item->accepted_quantity;
        if ($quantity < 1) {
            return;
        }
        [$account, $method] = $this->policy->receiptAccount($inventory->loadMissing('product'), $selectedAccountId);
        $this->increase($account, $inventory, $quantity, 'grn_allocation', $actor, $item, 'Accepted GRN stock allocation', [
            'allocation_method' => $method,
        ]);
        PurchaseReceiptAllocationLine::query()->create([
            'purchase_receipt_item_id' => $item->id, 'account_id' => $account->id,
            'quantity' => $quantity, 'allocation_method' => $method,
        ]);
    }

    public function reconcile(ProductInventory $inventory, InventoryAllocationAccount $target, int $quantity, User $actor, string $reason): void
    {
        DB::transaction(function () use ($inventory, $target, $quantity, $actor, $reason): void {
            $this->reconcileInventory($inventory, $target, $quantity, $actor, $reason, 'quantity');
        }, 5);
    }

    /**
     * @param  array<int, int>  $quantitiesByInventoryId
     */
    public function reconcileMany(array $quantitiesByInventoryId, InventoryAllocationAccount $target, User $actor, string $reason): void
    {
        DB::transaction(function () use ($quantitiesByInventoryId, $target, $actor, $reason): void {
            ksort($quantitiesByInventoryId);

            foreach ($quantitiesByInventoryId as $inventoryId => $quantity) {
                $inventory = ProductInventory::query()
                    ->with(['product:id,sku,name', 'warehouse:id,name'])
                    ->lockForUpdate()
                    ->findOrFail($inventoryId);

                $this->reconcileInventory(
                    $inventory,
                    $target,
                    $quantity,
                    $actor,
                    $reason,
                    "allocationQuantities.{$inventoryId}",
                );
            }
        }, 5);
    }

    private function reconcileInventory(
        ProductInventory $inventory,
        InventoryAllocationAccount $target,
        int $quantity,
        User $actor,
        string $reason,
        string $errorKey,
    ): void {
        $system = $this->systemAccount();
        $source = $this->balance($system, $inventory, true);
        if ($quantity < 1 || $source->availableQuantity() < $quantity || $target->is_system) {
            $product = $inventory->product;
            $warehouse = $inventory->warehouse;
            $available = $source->availableQuantity();
            $units = $available === 1 ? 'unit' : 'units';
            $label = $product === null
                ? "Inventory #{$inventory->id}"
                : trim("{$product->sku} — {$product->name}".($warehouse === null ? '' : " — {$warehouse->name}"));

            throw ValidationException::withMessages([
                $errorKey => "{$label} — Quantity cannot exceed the {$available} {$units} currently available.",
            ]);
        }
        $destination = $this->balance($target, $inventory, true);
        $source->decrement('allocated_quantity', $quantity);
        $destination->increment('allocated_quantity', $quantity);
        $this->event('reconciliation_transfer', $inventory, $quantity, $actor, $reason, null, $system, $target);
    }

    public function reserve(InventoryReservation $reservation, OrderItem $item, User $actor): void
    {
        $inventory = ProductInventory::query()->lockForUpdate()->findOrFail($reservation->product_inventory_id);
        $needed = $reservation->quantity;
        foreach ($this->candidateAccounts($item, $actor) as $account) {
            if ($needed === 0) {
                break;
            }
            $balance = $this->balance($account, $inventory, true);
            $take = min($needed, $balance->availableQuantity());
            if ($take < 1) {
                continue;
            }
            $balance->increment('reserved_quantity', $take);
            InventoryAllocationReservationLine::query()->create([
                'inventory_reservation_id' => $reservation->id, 'account_id' => $account->id,
                'quantity' => $take, 'status' => 'reserved',
            ]);
            $this->event($account->is_system ? 'legacy_system_reservation' : 'reservation', $inventory, $take, $actor,
                $account->is_system ? 'Migration/shadow legacy stock reservation' : 'Order allocation reservation', $reservation, $account);
            $needed -= $take;
        }
        if ($needed > 0) {
            throw ValidationException::withMessages(['items' => 'Insufficient explicitly allocated inventory for this Order.']);
        }
    }

    /** @param array<int, int> $quantitiesByAccountId */
    public function reserveExact(InventoryReservation $reservation, array $quantitiesByAccountId, User $actor): void
    {
        $inventory = ProductInventory::query()->lockForUpdate()->findOrFail($reservation->product_inventory_id);
        $quantitiesByAccountId = array_filter($quantitiesByAccountId, fn (int $quantity): bool => $quantity > 0);
        ksort($quantitiesByAccountId);

        if (array_sum($quantitiesByAccountId) !== $reservation->quantity) {
            throw ValidationException::withMessages(['execution' => 'Approved stock sources do not match the required reservation quantity.']);
        }

        foreach ($quantitiesByAccountId as $accountId => $quantity) {
            $account = InventoryAllocationAccount::query()->where('status', true)->findOrFail($accountId);
            $balance = $this->balance($account, $inventory, true);
            if ($balance->availableQuantity() < $quantity) {
                throw ValidationException::withMessages([
                    'execution' => "{$account->name} now has only {$balance->availableQuantity()} of the {$quantity} approved units available. This request cannot be executed.",
                ]);
            }
            $balance->increment('reserved_quantity', $quantity);
            InventoryAllocationReservationLine::query()->create([
                'inventory_reservation_id' => $reservation->id,
                'account_id' => $account->id,
                'quantity' => $quantity,
                'status' => 'reserved',
            ]);
            $this->event($account->is_system ? 'approved_system_reservation' : 'approved_source_reservation', $inventory, $quantity, $actor,
                'Approved Stock Request source reservation', $reservation, $account);
        }
    }

    public function transferExact(ProductInventory $inventory, InventoryAllocationAccount $source, InventoryAllocationAccount $destination, int $quantity, User $actor, Model $request): InventoryAllocationEvent
    {
        $lockedInventory = ProductInventory::query()->lockForUpdate()->findOrFail($inventory->id);
        $sourceBalance = $this->balance($source, $lockedInventory, true);
        if ($quantity < 1 || $sourceBalance->availableQuantity() < $quantity) {
            throw ValidationException::withMessages([
                'execution' => "{$source->name} now has only {$sourceBalance->availableQuantity()} of the {$quantity} approved units available. This request cannot be executed.",
            ]);
        }
        if ($source->is($destination)) {
            throw ValidationException::withMessages(['execution' => 'The approved source already belongs to the requester.']);
        }
        $destinationBalance = $this->balance($destination, $lockedInventory, true);
        $sourceBalance->decrement('allocated_quantity', $quantity);
        $destinationBalance->increment('allocated_quantity', $quantity);

        return $this->event('stock_request_transfer', $lockedInventory, $quantity, $actor,
            'Approved permanent Stock Request transfer', $request, $source, $destination);
    }

    public function release(InventoryReservation $reservation, User $actor, string $reason): void
    {
        InventoryAllocationReservationLine::query()->where('inventory_reservation_id', $reservation->id)
            ->where('status', 'reserved')->orderBy('id')->lockForUpdate()->get()->each(function ($line) use ($reservation, $actor, $reason): void {
                $balance = InventoryAllocationBalance::query()->where('account_id', $line->account_id)
                    ->where('product_inventory_id', $reservation->product_inventory_id)->lockForUpdate()->firstOrFail();
                if ($balance->reserved_quantity < $line->quantity) {
                    throw new InventoryInvariantException('Allocation reserved balance is inconsistent.');
                }
                $balance->decrement('reserved_quantity', $line->quantity);
                $line->forceFill(['status' => 'released'])->save();
                $this->event('reservation_release', $reservation->inventory, $line->quantity, $actor, $reason, $reservation, $balance->account);
            });
    }

    public function adjustReservation(InventoryReservation $reservation, OrderItem $item, int $newQuantity, User $actor): void
    {
        $current = (int) InventoryAllocationReservationLine::query()->where('inventory_reservation_id', $reservation->id)->where('status', 'reserved')->sum('quantity');
        $delta = $newQuantity - $current;
        if ($delta === 0) {
            return;
        }
        $inventory = ProductInventory::query()->lockForUpdate()->findOrFail($reservation->product_inventory_id);
        if ($delta > 0) {
            $needed = $delta;
            foreach ($this->candidateAccounts($item, $actor) as $account) {
                if ($needed === 0) {
                    break;
                }
                $balance = $this->balance($account, $inventory, true);
                $take = min($needed, $balance->availableQuantity());
                if ($take < 1) {
                    continue;
                }
                $balance->increment('reserved_quantity', $take);
                $line = InventoryAllocationReservationLine::query()->firstOrNew([
                    'inventory_reservation_id' => $reservation->id, 'account_id' => $account->id,
                ]);
                $line->quantity = ((int) $line->quantity) + $take;
                $line->status = 'reserved';
                $line->save();
                $this->event('reservation_increase', $inventory, $take, $actor, 'Controlled Order amendment', $reservation, $account);
                $needed -= $take;
            }
            if ($needed > 0) {
                throw ValidationException::withMessages(['items' => 'Insufficient allocated inventory for the Order amendment.']);
            }

            return;
        }
        $release = -$delta;
        $lines = InventoryAllocationReservationLine::query()->where('inventory_reservation_id', $reservation->id)
            ->where('status', 'reserved')->orderByDesc('id')->lockForUpdate()->get();
        foreach ($lines as $line) {
            if ($release === 0) {
                break;
            }
            $amount = min($release, $line->quantity);
            $balance = InventoryAllocationBalance::query()->where('account_id', $line->account_id)
                ->where('product_inventory_id', $inventory->id)->lockForUpdate()->firstOrFail();
            if ($balance->reserved_quantity < $amount) {
                throw new InventoryInvariantException('Allocation reserved balance is lower than the requested release.');
            }
            $balance->decrement('reserved_quantity', $amount);
            $line->forceFill(['quantity' => $line->quantity - $amount, 'status' => $line->quantity === $amount ? 'released' : 'reserved'])->save();
            $this->event('reservation_decrease', $inventory, $amount, $actor, 'Controlled Order amendment', $reservation, $balance->account);
            $release -= $amount;
        }
        if ($release > 0) {
            throw new InventoryInvariantException('Allocation reservation attribution is incomplete.');
        }
    }

    public function fulfill(InventoryReservation $reservation, User $actor): void
    {
        InventoryAllocationReservationLine::query()->where('inventory_reservation_id', $reservation->id)
            ->where('status', 'reserved')->orderBy('id')->lockForUpdate()->get()->each(function ($line) use ($reservation, $actor): void {
                $balance = InventoryAllocationBalance::query()->where('account_id', $line->account_id)
                    ->where('product_inventory_id', $reservation->product_inventory_id)->lockForUpdate()->firstOrFail();
                if ($balance->allocated_quantity < $line->quantity || $balance->reserved_quantity < $line->quantity) {
                    throw new InventoryInvariantException('Allocation balance cannot satisfy fulfilment.');
                }
                $balance->forceFill([
                    'allocated_quantity' => $balance->allocated_quantity - $line->quantity,
                    'reserved_quantity' => $balance->reserved_quantity - $line->quantity,
                ])->save();
                $line->forceFill(['status' => 'fulfilled'])->save();
                $this->event('fulfilment_consumption', $reservation->inventory, $line->quantity, $actor, 'Order fulfilment consumed allocated stock', $reservation, $balance->account);
            });
    }

    public function consumeDirect(OrderItem $item, ProductInventory $inventory, User $actor): void
    {
        $this->consumeDirectQuantity($item, $inventory, $item->ordered_quantity, $actor);
    }

    public function consumeDirectQuantity(OrderItem $item, ProductInventory $inventory, int $quantity, User $actor): void
    {
        $needed = $quantity;
        foreach ($this->candidateAccounts($item, $actor) as $account) {
            if ($needed === 0) {
                break;
            }
            $balance = $this->balance($account, $inventory, true);
            $take = min($needed, $balance->availableQuantity());
            if ($take < 1) {
                continue;
            }
            $balance->decrement('allocated_quantity', $take);
            $this->event($account->is_system ? 'legacy_system_consumption' : 'direct_fulfilment_consumption', $inventory, $take, $actor,
                $account->is_system ? 'Migration/shadow direct shipment from legacy stock' : 'Direct Order fulfilment', $item, $account);
            $needed -= $take;
        }
        if ($needed > 0) {
            throw ValidationException::withMessages(['items' => 'Insufficient explicitly allocated inventory for this shipment.']);
        }
    }

    public function ensureShadowCoverage(ProductInventory $inventory, User $actor): void
    {
        DB::transaction(function () use ($inventory, $actor): void {
            $locked = ProductInventory::query()->lockForUpdate()->findOrFail($inventory->id);
            $balances = InventoryAllocationBalance::query()->where('product_inventory_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            $missing = $locked->available_quantity - (int) $balances->sum('allocated_quantity');
            if ($missing <= 0 || $this->policy->mode() === InventoryAllocationMode::Strict) {
                return;
            }
            $this->increase($this->systemAccount(), $locked, $missing, 'shadow_reconciliation', $actor, $locked,
                'Migration/shadow physical stock reconciliation');
        }, 5);
    }

    public function recordOpeningStock(Model $source, ProductInventory $inventory, int $quantity, User $actor, ?int $selectedAccountId): void
    {
        if ($quantity < 1) {
            return;
        }
        $account = $selectedAccountId === null ? null : InventoryAllocationAccount::query()->where('status', true)->findOrFail($selectedAccountId);
        if ($this->policy->mode() === InventoryAllocationMode::Strict && ($account === null || $account->is_system)) {
            throw ValidationException::withMessages(['allocation_account_id' => 'Strict allocation requires an active Employee or Team account for available Opening Stock.']);
        }
        $account ??= $this->systemAccount();
        $this->increase($account, $inventory, $quantity, 'opening_stock_allocation', $actor, $source, 'Opening Stock allocation');
    }

    public function markDamaged(ProductInventory $inventory, int $quantity, Model $source, User $actor): void
    {
        $this->moveToHold('damaged', $source, $inventory, $quantity, $actor, 'damaged_allocation_hold');
    }

    public function restoreDamaged(ProductInventory $inventory, int $quantity, Model $source, User $actor): void
    {
        $remaining = $quantity;
        $lines = InventoryAllocationTransitionLine::query()->where('context_type', 'damaged')
            ->where('source_product_inventory_id', $inventory->id)->where('remaining_quantity', '>', 0)
            ->orderBy('id')->lockForUpdate()->get();
        foreach ($lines as $line) {
            if ($remaining === 0) {
                break;
            }
            $amount = min($remaining, $line->remaining_quantity);
            $account = InventoryAllocationAccount::query()->findOrFail($line->account_id);
            $this->increase($account, $inventory, $amount, 'damaged_allocation_restore', $actor, $source, 'Restored damaged stock to original allocation holder');
            $line->forceFill(['remaining_quantity' => $line->remaining_quantity - $amount, 'status' => $line->remaining_quantity === $amount ? 'restored' : 'held'])->save();
            $remaining -= $amount;
        }
        if ($remaining > 0) {
            if ($this->policy->mode() === InventoryAllocationMode::MigrationShadow) {
                $this->increase($this->systemAccount(), $inventory, $remaining, 'damaged_restore_unknown_system', $actor, $source, 'Restored legacy damaged stock with unknown allocation owner');
            } else {
                $this->event('damaged_restore_unallocated_strict', $inventory, $remaining, $actor, 'Restored damaged stock requires Owner/Admin reconciliation', $source);
            }
        }
    }

    public function dispatchTransfer(Model $item, ProductInventory $inventory, int $quantity, User $actor): void
    {
        $this->moveToHold('transfer', $item, $inventory, $quantity, $actor, 'warehouse_transfer_dispatch');
    }

    public function completeTransfer(Model $item, ProductInventory $destination, User $actor, bool $returned): void
    {
        $lines = InventoryAllocationTransitionLine::query()->where('context_type', 'transfer')->where('context_id', $item->getKey())
            ->where('remaining_quantity', '>', 0)->orderBy('id')->lockForUpdate()->get();
        foreach ($lines as $line) {
            $account = InventoryAllocationAccount::query()->findOrFail($line->account_id);
            $this->increase($account, $destination, $line->remaining_quantity, $returned ? 'warehouse_transfer_return' : 'warehouse_transfer_receipt', $actor, $item, $returned ? 'Transferred stock returned to source holder' : 'Transferred stock received for original holder');
            $line->forceFill(['destination_product_inventory_id' => $destination->id, 'remaining_quantity' => 0, 'status' => $returned ? 'returned' : 'received'])->save();
        }
    }

    public function restoreCustomerReturn(Model $returnItem, ProductInventory $inventory, int $quantity, Model $source, User $actor): void
    {
        $remaining = $quantity;
        $reservationIds = InventoryReservation::query()->where('order_item_id', $returnItem->order_item_id)->pluck('id');
        $sources = InventoryAllocationEvent::query()
            ->where('product_inventory_id', $inventory->id)
            ->where(function ($query) use ($reservationIds, $returnItem): void {
                $query->where(function ($reserved) use ($reservationIds): void {
                    $reserved->where('event_type', 'fulfilment_consumption')->whereIn('source_id', $reservationIds);
                })->orWhere(function ($direct) use ($returnItem): void {
                    $direct->whereIn('event_type', ['direct_fulfilment_consumption', 'legacy_system_consumption'])
                        ->where('source_type', (new OrderItem)->getMorphClass())
                        ->where('source_id', $returnItem->order_item_id);
                });
            })
            ->orderBy('id')->get();
        foreach ($sources as $event) {
            if ($remaining === 0) {
                break;
            }
            $already = (int) InventoryAllocationTransitionLine::query()->where('context_type', 'return')->where('order_item_id', $returnItem->order_item_id)->where('account_id', $event->from_account_id)->sum('quantity');
            $amount = min($remaining, max(0, $event->quantity - $already));
            if ($amount < 1) {
                continue;
            }
            $account = InventoryAllocationAccount::query()->findOrFail($event->from_account_id);
            $this->increase($account, $inventory, $amount, 'customer_return_restore', $actor, $source, 'Customer return restored original allocation holder');
            InventoryAllocationTransitionLine::query()->create(['context_type' => 'return', 'context_id' => $source->getKey(), 'account_id' => $account->id, 'source_product_inventory_id' => $inventory->id, 'order_item_id' => $returnItem->order_item_id, 'quantity' => $amount, 'remaining_quantity' => 0, 'status' => 'restored']);
            $remaining -= $amount;
        }
        if ($remaining > 0) {
            if ($this->policy->mode() === InventoryAllocationMode::MigrationShadow) {
                $this->increase($this->systemAccount(), $inventory, $remaining, 'customer_return_unknown_system', $actor, $source, 'Customer return source allocation could not be proven');
            } else {
                $this->event('customer_return_unallocated_strict', $inventory, $remaining, $actor, 'Customer return requires Owner/Admin reconciliation', $source);
            }
        }
    }

    public function employeeMetrics(int $employeeId, Collection $inventoryIds): Collection
    {
        $accountIds = InventoryAllocationAccount::query()->where('employee_id', $employeeId)
            ->orWhereIn('team_id', fn ($query) => $query->select('team_id')->from('employees')->where('id', $employeeId))->pluck('id');

        return InventoryAllocationBalance::query()->join('inventory_allocation_accounts as allocation_accounts', 'allocation_accounts.id', '=', 'inventory_allocation_balances.account_id')
            ->whereIn('product_inventory_id', $inventoryIds)
            ->selectRaw('product_inventory_id')
            ->selectRaw('SUM(CASE WHEN inventory_allocation_balances.account_id IN ('.($accountIds->isEmpty() ? '0' : $accountIds->implode(',')).') THEN allocated_quantity ELSE 0 END) allocated')
            ->selectRaw('SUM(CASE WHEN inventory_allocation_balances.account_id IN ('.($accountIds->isEmpty() ? '0' : $accountIds->implode(',')).') THEN reserved_quantity ELSE 0 END) reserved')
            ->selectRaw('SUM(CASE WHEN allocation_accounts.is_system = 0 AND inventory_allocation_balances.account_id NOT IN ('.($accountIds->isEmpty() ? '0' : $accountIds->implode(',')).') THEN allocated_quantity - reserved_quantity ELSE 0 END) other_allocated')
            ->selectRaw('SUM(CASE WHEN allocation_accounts.is_system = 1 THEN allocated_quantity - reserved_quantity ELSE 0 END) system_unallocated')
            ->selectRaw('SUM(allocated_quantity) ledger_allocated')
            ->groupBy('product_inventory_id')->get()->keyBy('product_inventory_id');
    }

    private function candidateAccounts(OrderItem $item, User $actor): array
    {
        $employeeId = $item->order->handled_by_employee_id ?? $actor->employee?->id;
        $accounts = [];
        if ($employeeId !== null) {
            $employee = Employee::query()->find($employeeId);
            $accounts[] = $this->employeeAccount($employeeId);
            if ($employee?->team_id !== null) {
                $accounts[] = $this->teamAccount($employee->team_id);
            }
        }
        $system = $this->systemAccount();
        $canUseSystem = $this->policy->mode() === InventoryAllocationMode::MigrationShadow;
        if ($canUseSystem) {
            $accounts[] = $system;
        }

        return collect($accounts)->unique('id')->values()->all();
    }

    private function increase(InventoryAllocationAccount $account, ProductInventory $inventory, int $quantity, string $type, ?User $actor, Model $source, string $reason, array $metadata = []): void
    {
        if (DB::transactionLevel() === 0) {
            throw new InventoryInvariantException('Allocation mutation requires an active transaction.');
        }
        $balance = $this->balance($account, $inventory, true);
        $balance->increment('allocated_quantity', $quantity);
        $total = (int) InventoryAllocationBalance::query()->where('product_inventory_id', $inventory->id)->sum('allocated_quantity');
        if ($total > $inventory->available_quantity) {
            throw new InventoryInvariantException('Allocation cannot exceed physical available inventory.');
        }
        $this->event($type, $inventory, $quantity, $actor, $reason, $source, null, $account, $metadata);
    }

    private function moveToHold(string $context, Model $source, ProductInventory $inventory, int $quantity, User $actor, string $eventType): void
    {
        $remaining = $quantity;
        $balances = InventoryAllocationBalance::query()->where('product_inventory_id', $inventory->id)
            ->whereRaw('allocated_quantity > reserved_quantity')->orderBy('id')->lockForUpdate()->get();
        foreach ($balances as $balance) {
            if ($remaining === 0) {
                break;
            }
            $amount = min($remaining, $balance->availableQuantity());
            if ($amount < 1) {
                continue;
            }
            $balance->decrement('allocated_quantity', $amount);
            InventoryAllocationTransitionLine::query()->create(['context_type' => $context, 'context_id' => $source->getKey(), 'account_id' => $balance->account_id, 'source_product_inventory_id' => $inventory->id, 'quantity' => $amount, 'remaining_quantity' => $amount, 'status' => $context === 'damaged' ? 'held' : 'dispatched']);
            $this->event($eventType, $inventory, $amount, $actor, $context === 'damaged' ? 'Sellable allocation held while stock is damaged' : 'Allocation dispatched with physical warehouse transfer', $source, $balance->account);
            $remaining -= $amount;
        }
        if ($remaining > 0) {
            throw new InventoryInvariantException('Allocated sellable stock cannot satisfy the physical stock transition.');
        }
    }

    private function balance(InventoryAllocationAccount $account, ProductInventory $inventory, bool $lock): InventoryAllocationBalance
    {
        InventoryAllocationBalance::query()->firstOrCreate(['account_id' => $account->id, 'product_inventory_id' => $inventory->id]);
        $query = InventoryAllocationBalance::query()->where('account_id', $account->id)->where('product_inventory_id', $inventory->id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function event(string $type, ProductInventory $inventory, int $quantity, ?User $actor, string $reason, ?Model $source = null, ?InventoryAllocationAccount $from = null, ?InventoryAllocationAccount $to = null, array $metadata = []): InventoryAllocationEvent
    {
        return InventoryAllocationEvent::query()->create([
            'event_key' => (string) Str::uuid(), 'event_type' => $type, 'product_inventory_id' => $inventory->id,
            'from_account_id' => $from?->id, 'to_account_id' => $to?->id, 'quantity' => $quantity,
            'source_type' => $source?->getMorphClass(), 'source_id' => $source?->getKey(),
            'order_id' => $source instanceof InventoryReservation ? $source->orderItem?->order_id : ($source instanceof OrderItem ? $source->order_id : null),
            'purchase_receipt_id' => $source instanceof PurchaseReceiptItem ? $source->purchase_receipt_id : null,
            'performed_by_user_id' => $actor?->id, 'reason' => $reason, 'metadata' => $metadata ?: null, 'created_at' => now(),
        ]);
    }
}
