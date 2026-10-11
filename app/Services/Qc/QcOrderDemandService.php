<?php

namespace App\Services\Qc;

use App\Enums\QcInspectionStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\QcCertificate;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Read-only forecast of Reserved Renewed demand not covered by eligible QC units. */
class QcOrderDemandService
{
    public function __construct(
        private readonly RenewedQcDispatchService $dispatch,
        private readonly QcOrderAssignmentService $assignments,
        private readonly RenewedQcRequirement $requirement,
    ) {}

    /** @return array{units_needed: int, orders_affected: int} */
    public function calculate(User $actor): array
    {
        $slots = $this->demandSlots($actor);
        if ($slots === []) {
            return ['units_needed' => 0, 'orders_affected' => 0];
        }

        $certificates = $this->availableCertificates();
        if ($certificates->isEmpty()) {
            $affectedOrders = [];
            foreach ($slots as $slot) {
                $affectedOrders[$slot['order']->id] = true;
            }

            return ['units_needed' => count($slots), 'orders_affected' => count($affectedOrders)];
        }

        // Deterministic augmenting paths produce a maximum one-to-one matching.
        // A flexible earlier demand can be moved to another compatible unit so
        // it cannot strand a later line that has fewer eligible certificates.
        $certificateToSlot = [];
        $compatibility = [];
        foreach (array_keys($slots) as $slotIndex) {
            $seenCertificates = [];
            $this->matchSlot($slotIndex, $seenCertificates, $certificateToSlot, $slots, $certificates, $compatibility);
        }

        $matchedSlots = array_fill_keys(array_values($certificateToSlot), true);
        $affectedOrders = [];
        foreach ($slots as $slotIndex => $slot) {
            if (! isset($matchedSlots[$slotIndex])) {
                $affectedOrders[$slot['order']->id] = true;
            }
        }

        return ['units_needed' => count($slots) - count($matchedSlots), 'orders_affected' => count($affectedOrders)];
    }

    /** @return list<array{order: Order, item: OrderItem}> */
    private function demandSlots(User $actor): array
    {
        $orders = $this->dispatch->queueQuery($actor)
            ->with([
                'items.product',
                'items.upgradeSelection',
                'items.qcAssignments.device',
                'items.qcAssignments.certificate.device',
                'items.qcAssignments.certificate.inspection',
            ])->get();
        $slots = [];

        foreach ($orders as $order) {
            if (! $this->assignments->canChange($order)) {
                continue;
            }

            $readiness = $this->dispatch->readiness($order, $order->items);
            $lines = collect($readiness['lines'])->keyBy('order_item_id');

            foreach ($order->items as $item) {
                if (! $this->requirement->requires($item)) {
                    continue;
                }

                $remaining = (int) ($lines->get($item->id)['remaining'] ?? 0);
                for ($unit = 0; $unit < $remaining; $unit++) {
                    $slots[] = ['order' => $order, 'item' => $item];
                }
            }
        }

        return $slots;
    }

    /** @return Collection<int, QcCertificate> */
    private function availableCertificates(): Collection
    {
        return QcCertificate::query()
            ->with(['device', 'inspection'])
            ->whereHas('inspection', fn (Builder $query) => $query->where('status', QcInspectionStatus::Completed->value))
            ->whereDoesntHave('device.orderAssignments', fn (Builder $query) => $query->active())
            ->whereDoesntHave('device.inspections', fn (Builder $query) => $query->whereNotNull('active_device_id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('qc_certificates as newer')
                ->whereColumn('newer.device_id', 'qc_certificates.device_id')
                ->whereColumn('newer.version', '>', 'qc_certificates.version'))
            ->orderBy('device_id')
            ->orderBy('id')
            // Guard against malformed historical data with multiple current
            // certificate rows for one physical device.
            ->get()
            ->unique('device_id')
            ->values();
    }

    /**
     * @param  array<int, bool>  $seenCertificates
     * @param  array<int, int>  $certificateToSlot
     * @param  list<array{order: Order, item: OrderItem}>  $slots
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
