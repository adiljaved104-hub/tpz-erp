<?php

namespace App\Services\Qc;

use App\Enums\EmployeeRole;
use App\Enums\InventoryLocationType;
use App\Enums\OrderStatus;
use App\Enums\ProductCondition;
use App\Enums\QcPermission;
use App\Enums\StockTransferPermission;
use App\Enums\StockTransferStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\QcCertificate;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Services\Authorization\QcAuthorization;
use App\Services\Authorization\StockTransferAuthorization;
use App\Services\StockTransfers\StockTransferResponsibilityScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Read-only forecast for Renewed units needed by Draft Marketplace Fulfilment transfers. */
class QcTransferDemandService
{
    public function __construct(
        private readonly QcAvailableCertificateService $availableSupply,
        private readonly QcCertificateEligibilityService $eligibility,
        private readonly QcOrderAssignmentService $orderAssignments,
        private readonly RenewedQcDispatchService $orderDispatch,
        private readonly QcStockTransferReadinessService $transferReadiness,
    ) {}

    /** @return array{units_needed:int,transfers_affected:int,marketplace_transfers:int} */
    public function summary(User $actor): array
    {
        $visibleWork = $this->visibleTransferWork($actor);
        if ($visibleWork->isEmpty()) {
            return ['units_needed' => 0, 'transfers_affected' => 0, 'marketplace_transfers' => 0];
        }

        $transferIds = $visibleWork->pluck('transfer.id')->map(fn ($id): int => (int) $id)->all();
        $shortages = $this->unmatchedTransferDemandByTransfer();
        $unitsNeeded = 0;
        $affectedTransfers = [];
        foreach ($transferIds as $transferId) {
            $shortage = (int) ($shortages[$transferId] ?? 0);
            if ($shortage > 0) {
                $unitsNeeded += $shortage;
                $affectedTransfers[$transferId] = true;
            }
        }

        return [
            'units_needed' => $unitsNeeded,
            'transfers_affected' => count($affectedTransfers),
            'marketplace_transfers' => $visibleWork->count(),
        ];
    }

    /** @return Collection<int, array{transfer:StockTransfer,readiness:array}> */
    public function queue(User $actor): Collection
    {
        $shortages = $this->unmatchedTransferDemandByTransfer();

        return $this->visibleTransferWork($actor)->map(function (array $work) use ($shortages): array {
            /** @var StockTransfer $transfer */
            $transfer = $work['transfer'];

            return [
                'id' => $transfer->id,
                'reference' => $transfer->reference,
                'status' => $transfer->status->value,
                'source_warehouse' => $transfer->sourceWarehouse?->name,
                'destination_marketplace_warehouse' => $transfer->destinationWarehouse?->name,
                'marketplace_platform' => $transfer->destinationWarehouse?->marketplacePlatform?->name,
                'readiness' => $work['readiness'],
                'units_needed' => (int) ($shortages[(int) $transfer->id] ?? 0),
            ];
        })->values();
    }

    public function unitsNeededForTransfer(User $actor, StockTransfer $transfer): int
    {
        $visible = $this->visibleTransferWork($actor)->contains(fn (array $work): bool => (int) $work['transfer']->id === (int) $transfer->id);
        if (! $visible) {
            return 0;
        }

        return (int) ($this->unmatchedTransferDemandByTransfer()[(int) $transfer->id] ?? 0);
    }

    public function canViewTransferQueue(User $actor): bool
    {
        if ($actor->employee?->role === EmployeeRole::Technician) {
            return app(QcAuthorization::class)->allows($actor, QcPermission::ViewMarketplaceTransferAssignments);
        }

        return app(QcAuthorization::class)->allows($actor, QcPermission::ViewOrderAssignments)
            && app(StockTransferAuthorization::class)->allows($actor, StockTransferPermission::View);
    }

    public function canViewTransfer(User $actor, StockTransfer $transfer): bool
    {
        if (! $this->canViewTransferQueue($actor)) {
            return false;
        }

        if ($actor->employee?->role !== EmployeeRole::Technician) {
            return app(StockTransferAuthorization::class)->allows($actor, StockTransferPermission::View, $transfer);
        }

        return $transfer->status === StockTransferStatus::Draft
            && $this->transferReadiness->isRequired($transfer)
            && app(StockTransferResponsibilityScopeService::class)->canAccessTransfer(
                $actor,
                $transfer,
            );
    }

    /** @return Collection<int, array{transfer:StockTransfer,readiness:array}> */
    private function visibleTransferWork(User $actor): Collection
    {
        if (! $this->canViewTransferQueue($actor)) {
            return collect();
        }

        return $this->draftMarketplaceTransfers()->filter(function (StockTransfer $transfer) use ($actor): bool {
            return $this->canViewTransfer($actor, $transfer);
        })->map(function (StockTransfer $transfer): array {
            $readiness = $this->transferReadiness->forTransfer($transfer);

            return ['transfer' => $transfer, 'readiness' => $readiness];
        })->filter(fn (array $work): bool => $work['readiness']['required'] > 0 && $work['readiness']['status'] !== 'ready')->values();
    }

    /** @return Collection<int, StockTransfer> */
    private function draftMarketplaceTransfers(): Collection
    {
        return StockTransfer::query()
            ->where('status', StockTransferStatus::Draft->value)
            ->whereHas('destinationWarehouse', fn (Builder $query) => $query->where('location_type', InventoryLocationType::MarketplaceFulfilment->value))
            ->whereHas('items.product', fn (Builder $query) => $query->where('condition', ProductCondition::Renewed->value))
            ->with([
                'sourceWarehouse:id,name,location_type,marketplace_platform_id',
                'destinationWarehouse:id,name,location_type,marketplace_platform_id',
                'destinationWarehouse.marketplacePlatform:id,name',
                'items.product:id,condition',
            ])
            ->orderBy('id')
            ->get();
    }

    /** @return list<array{type:string,order:Order,item:OrderItem}> */
    private function orderDemandSlots(): array
    {
        $orders = Order::query()
            ->where('status', OrderStatus::Reserved->value)
            ->whereHas('items.product', fn (Builder $query) => $query->where('condition', ProductCondition::Renewed->value))
            ->with([
                'items.product', 'items.upgradeSelection', 'items.qcAssignments.device',
                'items.qcAssignments.certificate.device', 'items.qcAssignments.certificate.inspection',
            ])
            ->orderBy('id')
            ->get();
        $slots = [];

        foreach ($orders as $order) {
            if (! $this->orderAssignments->canChange($order)) {
                continue;
            }
            $readiness = $this->orderDispatch->readiness($order, $order->items);
            $lines = collect($readiness['lines'])->keyBy('order_item_id');
            foreach ($order->items as $item) {
                if ($item->product?->condition !== ProductCondition::Renewed) {
                    continue;
                }
                $remaining = (int) ($lines->get($item->id)['remaining'] ?? 0);
                for ($unit = 0; $unit < $remaining; $unit++) {
                    $slots[] = ['type' => 'order', 'order' => $order, 'item' => $item];
                }
            }
        }

        return $slots;
    }

    /** @return list<array{type:string,transfer:StockTransfer,item:StockTransferItem}> */
    private function allTransferDemandSlots(): array
    {
        $slots = [];
        foreach ($this->draftMarketplaceTransfers() as $transfer) {
            $readiness = $this->transferReadiness->forTransfer($transfer);
            $remainingByItem = collect($readiness['lines'])->keyBy('stock_transfer_item_id');
            foreach ($transfer->items as $item) {
                if ($item->product?->condition !== ProductCondition::Renewed) {
                    continue;
                }
                $remaining = (int) ($remainingByItem->get($item->id)['remaining'] ?? 0);
                for ($unit = 0; $unit < $remaining; $unit++) {
                    $slots[] = ['type' => 'transfer', 'transfer' => $transfer, 'item' => $item];
                }
            }
        }

        return $slots;
    }

    /** @return array<int,int> */
    private function unmatchedTransferDemandByTransfer(): array
    {
        $slots = [...$this->orderDemandSlots(), ...$this->allTransferDemandSlots()];
        if ($slots === []) {
            return [];
        }

        $certificates = $this->availableSupply->currentUnclaimed();
        $certificateToSlot = [];
        $compatibility = [];
        foreach (array_keys($slots) as $slotIndex) {
            $seenCertificates = [];
            $this->matchSlot($slotIndex, $seenCertificates, $certificateToSlot, $slots, $certificates, $compatibility);
        }

        $matchedSlots = array_fill_keys(array_values($certificateToSlot), true);
        $shortages = [];
        foreach ($slots as $slotIndex => $slot) {
            if ($slot['type'] === 'transfer' && ! isset($matchedSlots[$slotIndex])) {
                $transferId = (int) $slot['transfer']->id;
                $shortages[$transferId] = ($shortages[$transferId] ?? 0) + 1;
            }
        }

        return $shortages;
    }

    /** @param array<int,bool> $seenCertificates @param array<int,int> $certificateToSlot @param list<array> $slots @param array<string,bool> $compatibility */
    private function matchSlot(int $slotIndex, array &$seenCertificates, array &$certificateToSlot, array $slots, Collection $certificates, array &$compatibility): bool
    {
        foreach ($certificates as $certificate) {
            $certificateId = (int) $certificate->id;
            if (isset($seenCertificates[$certificateId]) || ! $this->canSatisfy($slots[$slotIndex], $certificate, $compatibility)) {
                continue;
            }
            $seenCertificates[$certificateId] = true;
            if (! array_key_exists($certificateId, $certificateToSlot)
                || $this->matchSlot($certificateToSlot[$certificateId], $seenCertificates, $certificateToSlot, $slots, $certificates, $compatibility)) {
                $certificateToSlot[$certificateId] = $slotIndex;

                return true;
            }
        }

        return false;
    }

    /** @param array<string,bool> $compatibility */
    private function canSatisfy(array $slot, QcCertificate $certificate, array &$compatibility): bool
    {
        $key = $slot['type'].':'.$slot['item']->id.':'.$certificate->id;
        if (array_key_exists($key, $compatibility)) {
            return $compatibility[$key];
        }

        try {
            if ($slot['type'] === 'order') {
                $this->orderAssignments->validateCertificate($slot['item'], $slot['order'], $certificate);
            } elseif (! $this->eligibility->isValidForTransfer($certificate, (int) $slot['item']->product_id, (int) $slot['transfer']->source_warehouse_id)) {
                return $compatibility[$key] = false;
            }
        } catch (ValidationException) {
            return $compatibility[$key] = false;
        }

        return $compatibility[$key] = true;
    }
}
