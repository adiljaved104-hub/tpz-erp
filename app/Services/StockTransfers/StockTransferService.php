<?php

namespace App\Services\StockTransfers;

use App\DTOs\StockTransfers\CreateStockTransferData;
use App\Enums\InventoryLocationType;
use App\Enums\ProductStatus;
use App\Enums\StockTransferPermission;
use App\Enums\StockTransferStatus;
use App\Exceptions\DuplicateInventoryPostingException;
use App\Exceptions\InactiveInventorySubjectException;
use App\Exceptions\InsufficientInventoryException;
use App\Exceptions\InvalidStockTransferTransitionException;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\StockTransfer;
use App\Models\StockTransferStatusEvent;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ActivityLogger;
use App\Services\Authorization\StockTransferAuthorization;
use App\Services\Inventory\InventoryService;
use App\Services\ReferenceSequenceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class StockTransferService
{
    public function __construct(
        private readonly StockTransferAuthorization $authorization,
        private readonly StockTransferResponsibilityScopeService $scope,
        private readonly InventoryService $inventory,
        private readonly ReferenceSequenceService $references,
        private readonly ActivityLogger $activity,
    ) {}

    public function createDraft(CreateStockTransferData $data, User $actor): StockTransfer
    {
        $this->authorization->authorize($actor, StockTransferPermission::Create);
        $items = collect($data->items);
        $validated = Validator::make([
            'source_warehouse_id' => $data->sourceWarehouseId, 'destination_warehouse_id' => $data->destinationWarehouseId,
            'transfer_date' => $data->transferDate, 'handled_by_employee_id' => $data->handledByEmployeeId,
            'idempotency_key' => $data->idempotencyKey, 'notes' => filled($data->notes) ? trim($data->notes) : null,
            'items' => $items->map(fn ($item): array => ['product_id' => $item->productId, 'quantity' => $item->quantity])->all(),
        ], [
            'source_warehouse_id' => ['required', 'integer', 'different:destination_warehouse_id', 'exists:warehouses,id'],
            'destination_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'], 'transfer_date' => ['required', 'date'],
            'handled_by_employee_id' => ['nullable', 'integer', 'exists:employees,id'], 'idempotency_key' => ['required', 'uuid'],
            'notes' => ['nullable', 'string', 'max:5000'], 'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'], 'items.*.quantity' => ['required', 'integer', 'min:1'],
        ])->validate();

        if ($existing = StockTransfer::query()->where('idempotency_key', $validated['idempotency_key'])->first()) {
            return $existing;
        }
        $source = Warehouse::query()->findOrFail($validated['source_warehouse_id']);
        $destination = Warehouse::query()->findOrFail($validated['destination_warehouse_id']);
        $this->assertSelectableLocations($source, $destination);
        if ($validated['handled_by_employee_id'] !== null && ! Employee::query()->whereKey($validated['handled_by_employee_id'])->where('status', true)->exists()) {
            throw new InactiveInventorySubjectException('Handled By must be an active Employee.');
        }
        $products = Product::query()->whereIn('id', collect($validated['items'])->pluck('product_id'))->get()->keyBy('id');
        if ($products->count() !== count($validated['items']) || $products->contains(fn (Product $product): bool => $product->status !== ProductStatus::Active)) {
            throw new InactiveInventorySubjectException('Every Transfer Product must be active.');
        }
        if (! $this->scope->canAccessProducts($actor, $products->keys(), $source, $destination)) {
            throw new AuthorizationException;
        }
        $reference = $this->references->nextStockTransferReference();

        return DB::transaction(function () use ($validated, $actor, $products, $reference): StockTransfer {
            $transfer = StockTransfer::query()->create([
                'reference' => $reference, 'source_warehouse_id' => $validated['source_warehouse_id'], 'destination_warehouse_id' => $validated['destination_warehouse_id'],
                'status' => StockTransferStatus::Draft, 'transfer_date' => $validated['transfer_date'], 'handled_by_employee_id' => $validated['handled_by_employee_id'],
                'created_by_user_id' => $actor->id, 'idempotency_key' => $validated['idempotency_key'], 'notes' => $validated['notes'],
            ]);
            foreach ($validated['items'] as $line) {
                $product = $products[$line['product_id']];
                $transfer->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'sku' => $product->sku, 'quantity' => $line['quantity'], 'posting_key' => (string) Str::uuid()]);
            }
            $this->event($transfer, null, StockTransferStatus::Draft, $actor);
            $this->activity->log('stock_transfer.created', $actor, $transfer, $this->safeContext($transfer));

            return $transfer->load(['items', 'sourceWarehouse', 'destinationWarehouse']);
        }, 5);
    }

    public function dispatch(StockTransfer $transfer, string $idempotencyKey, User $actor): StockTransfer
    {
        Validator::make(['idempotency_key' => $idempotencyKey], ['idempotency_key' => ['required', 'uuid']])->validate();
        $this->authorization->authorize($actor, StockTransferPermission::Dispatch, $transfer);
        if ($transfer->status === StockTransferStatus::Dispatched && $transfer->dispatch_idempotency_key === $idempotencyKey) {
            return $transfer;
        }
        if (StockTransfer::query()->where('dispatch_idempotency_key', $idempotencyKey)->whereKeyNot($transfer->id)->exists()) {
            throw new DuplicateInventoryPostingException('Dispatch idempotency key is already used.');
        }
        $items = $transfer->items()->orderBy('product_id')->get();
        $movementReferences = $items->map(fn (): string => $this->references->nextStockMovementReference());

        return DB::transaction(function () use ($transfer, $idempotencyKey, $actor, $movementReferences): StockTransfer {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $this->authorization->authorize($actor, StockTransferPermission::Dispatch, $transfer);
            if ($transfer->status !== StockTransferStatus::Draft) {
                throw new InvalidStockTransferTransitionException('Only a Draft Transfer can be dispatched.');
            }
            [$source, $destination] = $this->lockLocations($transfer);
            $this->assertSelectableLocations($source, $destination);
            $items = $transfer->items()->orderBy('product_id')->lockForUpdate()->get();
            $inventories = ProductInventory::query()->where('warehouse_id', $source->id)->whereIn('product_id', $items->pluck('product_id'))->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');
            $group = (string) Str::uuid();
            foreach ($items as $index => $item) {
                $inventory = $inventories->get($item->product_id);
                if (! $inventory) {
                    throw new InsufficientInventoryException("No source inventory exists for {$item->sku}.");
                }
                $this->inventory->dispatchTransferItem($item, $inventory, $actor, $movementReferences[$index], $group);
                $item->forceFill(['source_product_inventory_id' => $inventory->id, 'dispatched_quantity' => $item->quantity, 'dispatch_unit_cost' => $inventory->average_cost])->save();
            }
            $transfer->forceFill(['status' => StockTransferStatus::Dispatched, 'dispatch_idempotency_key' => $idempotencyKey, 'dispatched_by_user_id' => $actor->id, 'dispatched_at' => now()])->save();
            $this->event($transfer, StockTransferStatus::Draft, StockTransferStatus::Dispatched, $actor);
            $this->activity->log('stock_transfer.dispatched', $actor, $transfer, $this->safeContext($transfer));

            return $transfer->refresh()->load('items');
        }, 5);
    }

    public function receive(StockTransfer $transfer, string $idempotencyKey, User $actor): StockTransfer
    {
        return $this->completeInbound($transfer, $idempotencyKey, $actor, returned: false);
    }

    public function returnToSource(StockTransfer $transfer, string $reason, string $idempotencyKey, User $actor): StockTransfer
    {
        Validator::make(['reason' => trim($reason)], ['reason' => ['required', 'string', 'max:2000']])->validate();

        return $this->completeInbound($transfer, $idempotencyKey, $actor, returned: true, reason: trim($reason));
    }

    public function cancel(StockTransfer $transfer, string $reason, string $idempotencyKey, User $actor): StockTransfer
    {
        Validator::make(['reason' => trim($reason), 'idempotency_key' => $idempotencyKey], ['reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'uuid']])->validate();
        $this->authorization->authorize($actor, StockTransferPermission::Cancel, $transfer);
        if ($transfer->status === StockTransferStatus::Cancelled && $transfer->cancellation_idempotency_key === $idempotencyKey) {
            return $transfer;
        }

        return DB::transaction(function () use ($transfer, $reason, $idempotencyKey, $actor): StockTransfer {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $this->authorization->authorize($actor, StockTransferPermission::Cancel, $transfer);
            if ($transfer->status !== StockTransferStatus::Draft) {
                throw new InvalidStockTransferTransitionException('Only a Draft Transfer can be cancelled.');
            }
            $transfer->forceFill(['status' => StockTransferStatus::Cancelled, 'cancellation_reason' => trim($reason), 'cancellation_idempotency_key' => $idempotencyKey, 'cancelled_by_user_id' => $actor->id, 'cancelled_at' => now()])->save();
            $this->event($transfer, StockTransferStatus::Draft, StockTransferStatus::Cancelled, $actor, trim($reason));
            $this->activity->log('stock_transfer.cancelled', $actor, $transfer, $this->safeContext($transfer) + ['reason_recorded' => true]);

            return $transfer->refresh();
        }, 5);
    }

    private function completeInbound(StockTransfer $transfer, string $idempotencyKey, User $actor, bool $returned, ?string $reason = null): StockTransfer
    {
        Validator::make(['idempotency_key' => $idempotencyKey], ['idempotency_key' => ['required', 'uuid']])->validate();
        $permission = $returned ? StockTransferPermission::Cancel : StockTransferPermission::Receive;
        $this->authorization->authorize($actor, $permission, $transfer);
        $keyColumn = $returned ? 'return_idempotency_key' : 'receive_idempotency_key';
        $targetStatus = $returned ? StockTransferStatus::Returned : StockTransferStatus::Received;
        if ($transfer->status === $targetStatus && $transfer->{$keyColumn} === $idempotencyKey) {
            return $transfer;
        }
        $items = $transfer->items()->orderBy('product_id')->get();
        $movementReferences = $items->map(fn (): string => $this->references->nextStockMovementReference());

        return DB::transaction(function () use ($transfer, $idempotencyKey, $actor, $returned, $reason, $keyColumn, $targetStatus, $movementReferences): StockTransfer {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $this->authorization->authorize($actor, $returned ? StockTransferPermission::Cancel : StockTransferPermission::Receive, $transfer);
            if ($transfer->status !== StockTransferStatus::Dispatched) {
                throw new InvalidStockTransferTransitionException('Only an In Transit Transfer can be completed.');
            }
            [$source, $destination] = $this->lockLocations($transfer);
            $target = $returned ? $source : $destination;
            if (! $target->status) {
                throw new InactiveInventorySubjectException('The receiving Inventory Location must be active.');
            }
            $items = $transfer->items()->orderBy('product_id')->lockForUpdate()->get();
            $group = (string) Str::uuid();
            foreach ($items as $index => $item) {
                [$inventory] = $returned
                    ? $this->inventory->returnTransferItemToSource($item->setRelation('transfer', $transfer), $actor, $movementReferences[$index], $group)
                    : $this->inventory->receiveTransferItem($item, $destination->id, $actor, $movementReferences[$index], $group);
                $item->forceFill($returned ? ['returned_quantity' => $item->dispatched_quantity] : ['received_quantity' => $item->dispatched_quantity, 'destination_product_inventory_id' => $inventory->id])->save();
            }
            $transfer->forceFill(['status' => $targetStatus, $keyColumn => $idempotencyKey, $returned ? 'returned_by_user_id' : 'received_by_user_id' => $actor->id, $returned ? 'returned_at' : 'received_at' => now(), 'return_reason' => $returned ? $reason : null])->save();
            $this->event($transfer, StockTransferStatus::Dispatched, $targetStatus, $actor, $reason);
            $this->activity->log($returned ? 'stock_transfer.returned' : 'stock_transfer.received', $actor, $transfer, $this->safeContext($transfer) + ['reason_recorded' => $returned]);

            return $transfer->refresh()->load('items');
        }, 5);
    }

    private function lockLocations(StockTransfer $transfer): array
    {
        $locations = Warehouse::query()->whereIn('id', [$transfer->source_warehouse_id, $transfer->destination_warehouse_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        return [$locations[$transfer->source_warehouse_id], $locations[$transfer->destination_warehouse_id]];
    }

    private function assertSelectableLocations(Warehouse $source, Warehouse $destination): void
    {
        if ($source->is($destination)) {
            throw new InvalidStockTransferTransitionException('Source and destination must differ.');
        }
        if (! $source->status || ! $destination->status) {
            throw new InactiveInventorySubjectException('Transfer locations must be active.');
        }
        if (in_array($source->location_type, [InventoryLocationType::Transit], true) || in_array($destination->location_type, [InventoryLocationType::Transit], true)) {
            throw new InvalidStockTransferTransitionException('Transit-type locations are not used by normal Stock Transfers.');
        }
    }

    private function event(StockTransfer $transfer, ?StockTransferStatus $from, StockTransferStatus $to, User $actor, ?string $reason = null): void
    {
        StockTransferStatusEvent::query()->create(['stock_transfer_id' => $transfer->id, 'from_status' => $from, 'to_status' => $to, 'actor_user_id' => $actor->id, 'reason' => $reason, 'context' => ['reference' => $transfer->reference], 'created_at' => now()]);
    }

    private function safeContext(StockTransfer $transfer): array
    {
        return ['transfer_reference' => $transfer->reference, 'source_warehouse_id' => $transfer->source_warehouse_id, 'destination_warehouse_id' => $transfer->destination_warehouse_id, 'status' => $transfer->status->value, 'product_ids' => $transfer->items()->pluck('product_id')->all(), 'quantities' => $transfer->items()->pluck('quantity')->all()];
    }
}
