<?php

namespace App\Services\StockTransfers;

use App\Enums\StockTransferPermission;
use App\Enums\StockTransferStatus;
use App\Exceptions\InvalidStockTransferTransitionException;
use App\Models\StockTransfer;
use App\Models\StockTransferMarketplaceLoss;
use App\Models\User;
use App\Services\Authorization\StockTransferAuthorization;
use App\Services\Inventory\InventoryService;
use App\Services\Qc\QcStockTransferReadinessService;
use App\Services\ReferenceSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockTransferAdvancedService
{
    public function __construct(
        private readonly StockTransferAuthorization $authorization,
        private readonly InventoryService $inventory,
        private readonly ReferenceSequenceService $references,
        private readonly QcStockTransferReadinessService $qc,
    ) {}

    /** @param array<int,int> $quantitiesByItemId */
    public function ship(StockTransfer $transfer, array $quantitiesByItemId, string $idempotencyKey, User $actor): StockTransfer
    {
        $this->authorization->authorize($actor, StockTransferPermission::Dispatch, $transfer);

        return DB::transaction(function () use ($transfer, $quantitiesByItemId, $idempotencyKey, $actor): StockTransfer {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if (! in_array($transfer->status, [StockTransferStatus::Draft, StockTransferStatus::Dispatched], true)) {
                throw new InvalidStockTransferTransitionException('Only Draft or In Transit transfers can be shipped.');
            }
            if ($transfer->status === StockTransferStatus::Draft) {
                $this->qc->assertReadyForDispatch($transfer);
            }
            foreach ($transfer->items()->lockForUpdate()->get() as $item) {
                $quantity = (int) ($quantitiesByItemId[$item->id] ?? 0);
                if ($quantity < 1) {
                    continue;
                }
                $inventory = $item->sourceInventory()->lockForUpdate()->firstOrFail();
                $this->inventory->dispatchTransferItem($item, $inventory, $actor, $this->references->nextStockMovementReference(), (string) Str::uuid(), $quantity, (string) Str::uuid());
                $item->forceFill([
                    'source_product_inventory_id' => $inventory->id,
                    'dispatched_quantity' => $item->dispatched_quantity + $quantity,
                    'dispatch_unit_cost' => $inventory->average_cost,
                ])->save();
            }
            if ($transfer->items()->whereColumn('dispatched_quantity', '>', 'quantity')->exists()) {
                throw ValidationException::withMessages(['items' => 'Shipment quantities exceed the requested transfer quantities.']);
            }
            $transfer->forceFill(['status' => StockTransferStatus::Dispatched, 'dispatch_idempotency_key' => $transfer->dispatch_idempotency_key ?? $idempotencyKey, 'dispatched_by_user_id' => $actor->id, 'dispatched_at' => $transfer->dispatched_at ?? now()])->save();

            return $transfer->refresh()->load('items');
        }, 5);
    }

    /** @param array<int,int> $quantitiesByItemId */
    public function receive(StockTransfer $transfer, array $quantitiesByItemId, string $idempotencyKey, User $actor): StockTransfer
    {
        $this->authorization->authorize($actor, StockTransferPermission::Receive, $transfer);

        return DB::transaction(function () use ($transfer, $quantitiesByItemId, $idempotencyKey, $actor): StockTransfer {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($transfer->status !== StockTransferStatus::Dispatched) {
                throw new InvalidStockTransferTransitionException('Only an In Transit transfer can be received.');
            }
            foreach ($transfer->items()->lockForUpdate()->get() as $item) {
                $quantity = (int) ($quantitiesByItemId[$item->id] ?? 0);
                if ($quantity < 1) {
                    continue;
                }
                [$inventory] = $this->inventory->receiveTransferItem($item, $transfer->destination_warehouse_id, $actor, $this->references->nextStockMovementReference(), (string) Str::uuid(), $quantity);
                $item->forceFill(['destination_product_inventory_id' => $inventory->id, 'received_quantity' => $item->received_quantity + $quantity])->save();
            }
            $complete = ! $transfer->items()->whereColumn('received_quantity', '<', 'dispatched_quantity')->exists();
            $transfer->forceFill(['status' => $complete ? StockTransferStatus::Received : StockTransferStatus::Dispatched, 'receive_idempotency_key' => $complete ? $idempotencyKey : $transfer->receive_idempotency_key, 'received_by_user_id' => $actor->id, 'received_at' => $complete ? now() : $transfer->received_at])->save();

            return $transfer->refresh()->load('items');
        }, 5);
    }

    public function reportMarketplaceLoss(StockTransfer $transfer, int $itemId, int $quantity, string $key, User $actor, ?string $marketplaceReference = null, ?string $notes = null): StockTransferMarketplaceLoss
    {
        $this->authorization->authorize($actor, StockTransferPermission::Receive, $transfer);

        return StockTransferMarketplaceLoss::query()->firstOrCreate(['idempotency_key' => $key], [
            'stock_transfer_id' => $transfer->id, 'stock_transfer_item_id' => $itemId, 'quantity' => $quantity,
            'status' => 'pending', 'marketplace_reference' => $marketplaceReference, 'notes' => $notes,
            'reported_by_user_id' => $actor->id,
        ]);
    }
}
