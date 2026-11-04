<?php

namespace App\Services\StockTransfers;

use App\DTOs\Inventory\ReleaseReservationData;
use App\DTOs\Inventory\ReserveInventoryData;
use App\Enums\StockTransferPermission;
use App\Enums\StockTransferStatus;
use App\Exceptions\InvalidStockTransferTransitionException;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\StockTransferReservation;
use App\Models\User;
use App\Services\Authorization\StockTransferAuthorization;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockTransferReservationService
{
    public function __construct(
        private readonly StockTransferAuthorization $authorization,
        private readonly InventoryService $inventory,
    ) {}

    public function reserve(StockTransfer $transfer, string $idempotencyKey, User $actor): StockTransfer
    {
        $this->authorization->authorize($actor, StockTransferPermission::Dispatch, $transfer);

        return DB::transaction(function () use ($transfer, $idempotencyKey, $actor): StockTransfer {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $this->authorization->authorize($actor, StockTransferPermission::Dispatch, $transfer);
            if ($transfer->status !== StockTransferStatus::Draft) {
                throw new InvalidStockTransferTransitionException('Only a Draft Transfer can be reserved.');
            }
            foreach ($transfer->items()->lockForUpdate()->get() as $item) {
                $existing = StockTransferReservation::query()->where('stock_transfer_item_id', $item->id)->where('status', 'active')->first();
                if ($existing !== null) {
                    continue;
                }
                $inventory = $item->sourceInventory()->first();
                if ($inventory === null) {
                    $inventory = $item->product()->firstOrFail()->inventories()->where('warehouse_id', $transfer->source_warehouse_id)->firstOrFail();
                }
                $result = $this->inventory->reserve(new ReserveInventoryData(
                    (int) $inventory->id,
                    (int) $item->quantity,
                    'Stock Transfer reservation '.$transfer->reference,
                    (string) Str::uuid(),
                ), $actor);
                StockTransferReservation::query()->create([
                    'stock_transfer_id' => $transfer->id,
                    'stock_transfer_item_id' => $item->id,
                    'inventory_reservation_id' => $result->source->id,
                    'quantity' => $item->quantity,
                    'status' => 'active',
                    'idempotency_key' => $idempotencyKey.'-'.$item->id,
                    'created_by_user_id' => $actor->id,
                ]);
            }

            return $transfer->refresh()->load('reservations');
        }, 5);
    }

    public function releaseForCancellation(StockTransfer $transfer, User $actor): void
    {
        foreach ($transfer->reservations()->where('status', 'active')->with('reservation')->lockForUpdate()->get() as $link) {
            $this->inventory->release($link->reservation, new ReleaseReservationData(
                'Stock Transfer cancelled.', (string) Str::uuid(),
            ), $actor);
            $link->forceFill(['status' => 'released', 'released_at' => now()])->save();
        }
    }

    public function consumeForDispatch(StockTransferItem $item, User $actor): void
    {
        $links = StockTransferReservation::query()
            ->where('stock_transfer_item_id', $item->id)
            ->where('status', 'active')
            ->with('reservation')
            ->lockForUpdate()
            ->get();

        foreach ($links as $link) {
            $this->inventory->release($link->reservation, new ReleaseReservationData(
                'Stock Transfer dispatched; reservation consumed.', (string) Str::uuid(),
            ), $actor);
            $link->forceFill(['status' => 'fulfilled', 'fulfilled_at' => now()])->save();
        }
    }
}
