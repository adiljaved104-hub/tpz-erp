<?php

namespace App\Services\StockTransfers;

use App\Enums\StockTransferPermission;
use App\Enums\StockTransferStatus;
use App\Exceptions\InsufficientInventoryException;
use App\Exceptions\InvalidStockTransferTransitionException;
use App\Models\StockTransfer;
use App\Models\StockTransferMarketplaceLoss;
use App\Models\User;
use App\Services\ActivityLogger;
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
        private readonly StockTransferReservationService $reservations,
        private readonly ActivityLogger $activity,
    ) {}

    /** @param array<int,int> $quantitiesByItemId */
    public function ship(StockTransfer $transfer, array $quantitiesByItemId, string $idempotencyKey, User $actor): StockTransfer
    {
        $this->authorization->authorize($actor, StockTransferPermission::Dispatch, $transfer);
        $knownItemIds = $transfer->items()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        foreach (array_keys($quantitiesByItemId) as $itemId) {
            if (! in_array((int) $itemId, $knownItemIds, true)) {
                throw ValidationException::withMessages(['items' => 'The shipment contains an invalid transfer item.']);
            }
        }
        if (collect($quantitiesByItemId)->every(fn ($quantity): bool => (int) $quantity < 1)) {
            throw ValidationException::withMessages(['items' => 'At least one positive shipment quantity is required.']);
        }

        $movementReferences = [];
        foreach (array_keys($quantitiesByItemId) as $itemId) {
            $movementReferences[$itemId] = $this->references->nextStockMovementReference();
        }
        $reservationMovementReferences = $transfer->reservations()->where('status', 'active')->pluck('id')->mapWithKeys(fn ($id): array => [$id => [
            'release' => $this->references->nextStockMovementReference(),
            'reservation' => $this->references->nextInventoryReservationReference(),
            'reservation_movement' => $this->references->nextStockMovementReference(),
        ]])->all();

        return DB::transaction(function () use ($transfer, $quantitiesByItemId, $idempotencyKey, $actor, $movementReferences, $reservationMovementReferences): StockTransfer {
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
                $inventory = $item->sourceInventory()->lockForUpdate()->first();
                if ($inventory === null) {
                    $inventory = $item->product()->firstOrFail()
                        ->inventories()
                        ->where('warehouse_id', $transfer->source_warehouse_id)
                        ->lockForUpdate()
                        ->first();

                    if ($inventory === null) {
                        throw new InsufficientInventoryException("No source inventory exists for {$item->sku} at the transfer source warehouse.");
                    }
                }
                $reservation = $transfer->reservations()->where('stock_transfer_item_id', $item->id)->where('status', 'active')->first();
                $refs = $reservation ? ($reservationMovementReferences[$reservation->id] ?? []) : [];
                $this->reservations->consumeForDispatch($item, $quantity, $actor, $refs['release'] ?? null, $refs['reservation'] ?? null, $refs['reservation_movement'] ?? null);
                $this->inventory->dispatchTransferItem($item, $inventory, $actor, $movementReferences[$item->id], (string) Str::uuid(), $quantity, (string) Str::uuid());
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
        $knownItemIds = $transfer->items()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        foreach (array_keys($quantitiesByItemId) as $itemId) {
            if (! in_array((int) $itemId, $knownItemIds, true)) {
                throw ValidationException::withMessages(['items' => 'The receipt contains an invalid transfer item.']);
            }
        }
        if (collect($quantitiesByItemId)->every(fn ($quantity): bool => (int) $quantity < 1)) {
            throw ValidationException::withMessages(['items' => 'At least one positive receipt quantity is required.']);
        }

        $movementReferences = [];
        foreach (array_keys($quantitiesByItemId) as $itemId) {
            $movementReferences[$itemId] = $this->references->nextStockMovementReference();
        }

        return DB::transaction(function () use ($transfer, $quantitiesByItemId, $idempotencyKey, $actor, $movementReferences): StockTransfer {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($transfer->status !== StockTransferStatus::Dispatched) {
                throw new InvalidStockTransferTransitionException('Only an In Transit transfer can be received.');
            }
            foreach ($transfer->items()->lockForUpdate()->get() as $item) {
                $quantity = (int) ($quantitiesByItemId[$item->id] ?? 0);
                if ($quantity < 1) {
                    continue;
                }
                [$inventory] = $this->inventory->receiveTransferItem($item, $transfer->destination_warehouse_id, $actor, $movementReferences[$item->id], (string) Str::uuid(), $quantity);
                $item->forceFill(['destination_product_inventory_id' => $inventory->id, 'received_quantity' => $item->received_quantity + $quantity])->save();
            }
            $complete = ! $transfer->items()->whereColumn('received_quantity', '<', 'quantity')->exists();
            $transfer->forceFill(['status' => $complete ? StockTransferStatus::Received : StockTransferStatus::Dispatched, 'receive_idempotency_key' => $complete ? $idempotencyKey : $transfer->receive_idempotency_key, 'received_by_user_id' => $actor->id, 'received_at' => $complete ? now() : $transfer->received_at])->save();

            return $transfer->refresh()->load('items');
        }, 5);
    }

    public function reportMarketplaceLoss(StockTransfer $transfer, int $itemId, int $quantity, string $key, User $actor, ?string $marketplaceReference = null, ?string $notes = null): StockTransferMarketplaceLoss
    {
        $this->authorization->authorize($actor, StockTransferPermission::Receive, $transfer);

        if ($quantity < 1 || ! $transfer->items()->whereKey($itemId)->exists()) {
            throw ValidationException::withMessages(['loss' => 'Select a valid positive quantity for a transfer item.']);
        }
        $item = $transfer->items()->findOrFail($itemId);
        $alreadyReported = (int) $item->marketplaceLosses()->whereIn('status', ['pending', 'investigating', 'claim_submitted', 'reimbursed', 'rejected', 'resolved'])->sum('quantity');
        if ($quantity + $alreadyReported > (int) $item->received_quantity) {
            throw ValidationException::withMessages(['loss' => 'Marketplace loss cannot exceed the quantity physically received at destination.']);
        }

        return StockTransferMarketplaceLoss::query()->firstOrCreate(['idempotency_key' => $key], [
            'stock_transfer_id' => $transfer->id, 'stock_transfer_item_id' => $itemId, 'quantity' => $quantity,
            'status' => 'pending', 'marketplace_reference' => $marketplaceReference, 'notes' => $notes,
            'reported_by_user_id' => $actor->id,
        ]);
    }

    public function investigateMarketplaceLoss(StockTransferMarketplaceLoss $loss, User $actor, ?string $notes = null): StockTransferMarketplaceLoss
    {
        return DB::transaction(function () use ($loss, $actor, $notes): StockTransferMarketplaceLoss {
            $loss = StockTransferMarketplaceLoss::query()->lockForUpdate()->findOrFail($loss->id);
            $this->authorization->authorize($actor, StockTransferPermission::Receive, $loss->transfer);
            if ($loss->status !== 'pending') {
                throw ValidationException::withMessages(['loss' => 'Only reported losses can enter investigation.']);
            }
            $loss->forceFill(['status' => 'investigating', 'investigating_at' => now(), 'investigating_by_user_id' => $actor->id, 'notes' => $notes ?? $loss->notes])->save();
            $this->activity->log('stock_transfer.marketplace_loss_investigating', $actor, $loss, ['transfer_id' => $loss->stock_transfer_id, 'quantity' => $loss->quantity]);

            return $loss->refresh();
        });
    }

    public function submitMarketplaceClaim(StockTransferMarketplaceLoss $loss, User $actor, string $claimReference, ?string $notes = null, ?string $claimAmount = null): StockTransferMarketplaceLoss
    {
        return DB::transaction(function () use ($loss, $actor, $claimReference, $notes, $claimAmount): StockTransferMarketplaceLoss {
            $loss = StockTransferMarketplaceLoss::query()->lockForUpdate()->findOrFail($loss->id);
            $this->authorization->authorize($actor, StockTransferPermission::Receive, $loss->transfer);
            if ($loss->status !== 'investigating') {
                throw ValidationException::withMessages(['loss' => 'Only investigated losses can be submitted.']);
            }
            $item = $loss->item()->with('destinationInventory')->firstOrFail();
            if ($item->destinationInventory === null) {
                throw ValidationException::withMessages(['loss' => 'The received destination inventory could not be resolved.']);
            }
            $this->inventory->reconcileMarketplaceLoss($item->destinationInventory, (int) $loss->quantity, $loss, $actor, $this->references->nextStockMovementReference(), 'marketplace-loss-'.$loss->id);
            $loss->forceFill(['status' => 'claim_submitted', 'claim_reference' => trim($claimReference), 'claim_amount' => $claimAmount, 'claim_submitted_at' => now(), 'claim_submitted_by_user_id' => $actor->id, 'notes' => $notes ?? $loss->notes])->save();
            $this->activity->log('stock_transfer.marketplace_loss_claim_submitted', $actor, $loss, ['claim_reference' => $loss->claim_reference]);

            return $loss->refresh();
        });
    }

    public function closeMarketplaceClaim(StockTransferMarketplaceLoss $loss, User $actor, string $outcome, ?string $notes = null, ?string $reimbursedAmount = null): StockTransferMarketplaceLoss
    {
        if (! in_array($outcome, ['reimbursed', 'rejected', 'resolved'], true)) {
            throw ValidationException::withMessages(['outcome' => 'Invalid marketplace claim outcome.']);
        }

        return DB::transaction(function () use ($loss, $actor, $outcome, $notes, $reimbursedAmount): StockTransferMarketplaceLoss {
            $loss = StockTransferMarketplaceLoss::query()->lockForUpdate()->findOrFail($loss->id);
            $this->authorization->authorize($actor, StockTransferPermission::Receive, $loss->transfer);
            if ($loss->status !== 'claim_submitted') {
                throw ValidationException::withMessages(['loss' => 'Only submitted claims can be closed.']);
            }
            $loss->forceFill(['status' => $outcome, 'resolved_by_user_id' => $actor->id, 'resolved_at' => now(), 'closed_at' => now(), 'reimbursed_amount' => $reimbursedAmount, 'notes' => $notes ?? $loss->notes])->save();
            $this->activity->log('stock_transfer.marketplace_loss_closed', $actor, $loss, ['outcome' => $outcome, 'reimbursed_amount' => $reimbursedAmount]);

            return $loss->refresh();
        });
    }
}
