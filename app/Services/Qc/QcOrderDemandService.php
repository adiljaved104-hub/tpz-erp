<?php

namespace App\Services\Qc;

use App\Enums\OrderStatus;
use App\Enums\ProductCondition;
use App\Enums\QcPermission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\QcCertificate;
use App\Models\User;
use App\Services\Authorization\QcAuthorization;
use App\Services\Orders\OrderResponsibilityScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Read-only forecast of open Renewed Order demand not covered by eligible QC units. */
class QcOrderDemandService
{
    public function __construct(
        private readonly RenewedQcDispatchService $dispatch,
        private readonly QcOrderAssignmentService $assignments,
        private readonly RenewedQcRequirement $requirement,
        private readonly QcAvailableCertificateService $availableSupply,
    ) {}

    /** @return array{units_needed: int, orders_affected: int} */
    public function calculate(User $actor): array
    {
        $this->dispatch->authorizeQueue($actor);

        $forecast = $this->calculateAuthorizedForecast($actor);

        return ['units_needed' => $forecast['units_needed'], 'orders_affected' => $forecast['orders_affected']];
    }

    /** Reserved-only preparation forecast; the broader open-order forecast remains separate. */
    public function calculateReserved(User $actor): array
    {
        $this->dispatch->authorizeQueue($actor);
        $forecast = $this->calculateAuthorizedForecast($actor, reservedOnly: true);

        return ['units_needed' => $forecast['units_needed'], 'orders_affected' => $forecast['orders_affected']];
    }

    /**
     * Aggregate-only projection for an authorized QC queue. It intentionally
     * does not require Order View and never returns Order/customer details.
     *
     * @return array{units_needed: int, orders_affected: int, by_product_location: array<int, array<int, int>>}
     */
    public function calculateForQcQueue(User $actor): array
    {
        $this->authorizePreparation($actor);

        return $this->calculateAuthorizedForecast($actor);
    }

    /** @return array{units_needed: int, orders_affected: int, by_product_location: array<int, array<int, int>>} */
    private function calculateAuthorizedForecast(User $actor, bool $reservedOnly = false): array
    {
        $slots = $this->unmatchedDemandSlots($actor, $reservedOnly);

        return [
            'units_needed' => count($slots),
            'orders_affected' => count(array_unique(array_map(fn (array $slot): int => $slot['order']->id, $slots))),
            'by_product_location' => $this->groupUnmatchedSlots($slots),
        ];
    }

    /** Safe Product/location projection of exactly the same unmatched forecast slots. */
    public function openOrderDemand(User $actor): array
    {
        $this->authorizePreparation($actor);
        $rows = [];
        $orders = [];
        foreach ($this->unmatchedDemandSlots($actor) as $slot) {
            $item = $slot['item'];
            $order = $slot['order'];
            $key = $item->product_id.':'.$order->warehouse_id;
            $rows[$key] ??= [
                'product_id' => (int) $item->product_id,
                'sku' => $item->product->sku,
                'product_title' => $item->product->name,
                'warehouse_id' => (int) $order->warehouse_id,
                'warehouse' => $order->warehouse->name,
                'units_needed' => 0,
                'orders_affected' => 0,
            ];
            $rows[$key]['units_needed']++;
            $orders[$key][$order->id] = true;
            $rows[$key]['orders_affected'] = count($orders[$key]);
        }

        return array_values($rows);
    }

    private function authorizePreparation(User $actor): void
    {
        $authorization = app(QcAuthorization::class);
        $authorization->authorize($actor, QcPermission::View);
        $authorization->authorize($actor, QcPermission::Start);
    }

    /** @return list<array{order: Order, item: OrderItem, can_use_ready_certificate: bool}> */
    private function unmatchedDemandSlots(User $actor, bool $reservedOnly = false): array
    {
        $slots = $this->demandSlots($actor, $reservedOnly);
        if ($slots === []) {
            return [];
        }

        $certificates = $this->availableSupply->currentUnclaimed();
        if ($certificates->isEmpty()) {
            return $slots;
        }

        // Preserve the authoritative maximum one-to-one matching for every projection.
        $certificateToSlot = [];
        $compatibility = [];
        foreach (array_keys($slots) as $slotIndex) {
            $seenCertificates = [];
            $this->matchSlot($slotIndex, $seenCertificates, $certificateToSlot, $slots, $certificates, $compatibility);
        }

        $matchedSlots = array_fill_keys(array_values($certificateToSlot), true);

        return array_values(array_filter($slots, fn (int $index): bool => ! isset($matchedSlots[$index]), ARRAY_FILTER_USE_KEY));
    }

    /** @param list<array{order: Order, item: OrderItem, can_use_ready_certificate: bool}> $slots
     * @return array<int, array<int, int>>
     */
    private function groupUnmatchedSlots(array $slots): array
    {
        $byProductLocation = [];
        foreach ($slots as $slot) {
            $productId = (int) $slot['item']->product_id;
            $warehouseId = (int) $slot['order']->warehouse_id;
            $byProductLocation[$productId][$warehouseId] = ($byProductLocation[$productId][$warehouseId] ?? 0) + 1;
        }

        return $byProductLocation;
    }

    /** @return list<array{order: Order, item: OrderItem, can_use_ready_certificate: bool}> */
    private function demandSlots(User $actor, bool $reservedOnly = false): array
    {
        $orders = $this->openRenewedDemandOrders($actor)
            ->when($reservedOnly, fn (Builder $query) => $query->where('status', OrderStatus::Reserved->value))
            ->with([
                'warehouse:id,name',
                'items.product',
                'items.upgradeSelection',
                'items.qcAssignments.device',
                'items.qcAssignments.certificate.device',
                'items.qcAssignments.certificate.inspection',
            ])->get();
        $slots = [];

        foreach ($orders as $order) {
            $isDraft = $order->status === OrderStatus::Draft;
            $canUseReadyCertificate = ! $isDraft && $this->assignments->canChange($order);
            $readiness = $this->dispatch->readiness($order, $order->items);
            $lines = collect($readiness['lines'])->keyBy('order_item_id');

            foreach ($order->items as $item) {
                if (! $this->requirement->requires($item)) {
                    continue;
                }

                $remaining = $isDraft
                    ? $this->requirement->requiredQuantity($item)
                    : ($canUseReadyCertificate
                        ? (int) ($lines->get($item->id)['remaining'] ?? 0)
                        : $this->requirement->requiredQuantity($item));
                for ($unit = 0; $unit < $remaining; $unit++) {
                    $slots[] = [
                        'order' => $order,
                        'item' => $item,
                        // Drafts cannot be assigned or reserved, but a compatible ready
                        // certificate is still existing supply for this read-only forecast.
                        'can_use_ready_certificate' => $isDraft || $canUseReadyCertificate,
                    ];
                }
            }
        }

        return $slots;
    }

    /**
     * Forecasting is broader than dispatch eligibility: open renewed Orders
     * contribute demand before reservation, while actual assignment/shipping
     * continues to use RenewedQcDispatchService's Reserved-only queue.
     *
     * @return Builder<Order>
     */
    private function openRenewedDemandOrders(User $actor): Builder
    {
        $query = Order::query()
            ->whereIn('status', [
                OrderStatus::Draft->value,
                OrderStatus::PendingReview->value,
                OrderStatus::Confirmed->value,
                OrderStatus::Reserved->value,
                OrderStatus::Processing->value,
            ])
            ->whereNull('delivered_at')
            ->whereDoesntHave('fulfillment')
            ->whereHas('items.product', fn (Builder $products) => $products->where('condition', ProductCondition::Renewed->value))
            ->orderBy('order_date')
            ->orderBy('id');

        return app(OrderResponsibilityScopeService::class)->applyOrders($query, $actor);
    }

    /**
     * @param  array<int, bool>  $seenCertificates
     * @param  array<int, int>  $certificateToSlot
     * @param  list<array{order: Order, item: OrderItem, can_use_ready_certificate: bool}>  $slots
     * @param  array<string, bool>  $compatibility
     */
    private function matchSlot(
        int $slotIndex,
        array &$seenCertificates,
        array &$certificateToSlot,
        array $slots,
        Collection $certificates,
        array &$compatibility,
    ): bool {
        foreach ($certificates as $certificate) {
            $certificateId = (int) $certificate->id;
            if (! $slots[$slotIndex]['can_use_ready_certificate']
                || isset($seenCertificates[$certificateId])
                || ! $this->canSatisfy($slots[$slotIndex], $certificate, $compatibility)) {
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

    /** @param array<string, bool> $compatibility */
    private function canSatisfy(array $slot, QcCertificate $certificate, array &$compatibility): bool
    {
        $item = $slot['item'];
        $key = $item->id.':'.$certificate->id;
        if (array_key_exists($key, $compatibility)) {
            return $compatibility[$key];
        }

        try {
            $this->assignments->validateCertificate($item, $slot['order'], $certificate);
        } catch (ValidationException) {
            return $compatibility[$key] = false;
        }

        return $compatibility[$key] = true;
    }
}
