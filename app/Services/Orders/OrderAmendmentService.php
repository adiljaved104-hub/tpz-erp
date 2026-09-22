<?php

namespace App\Services\Orders;

use App\DTOs\Orders\OrderItemData;
use App\Enums\InventoryReservationStatus;
use App\Enums\OrderPermission;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderAmendment;
use App\Models\OrderItem;
use App\Models\OrderSetting;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\TaxInvoice;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Inventory\InventoryService;
use App\Services\ReferenceSequenceService;
use App\Services\Responsibilities\ResponsibilityAllocationService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderAmendmentService
{
    public const DEFAULT_WINDOW_HOURS = 1;

    public const WINDOW_OPTIONS = [1, 2, 5, 12, 24];

    public function __construct(
        private readonly OrderAuthorization $authorization,
        private readonly InventoryService $inventory,
        private readonly ResponsibilityAllocationService $allocations,
        private readonly OrderResponsibilityScopeService $responsibilities,
        private readonly OrderTotalsCalculator $totals,
        private readonly ExternalOrderIdentityService $externalIdentity,
        private readonly ReferenceSequenceService $references,
        private readonly ActivityLogger $activity,
    ) {}

    public function windowHours(): int
    {
        return (int) (OrderSetting::query()->whereKey(1)->value('amendment_window_hours') ?? self::DEFAULT_WINDOW_HOURS);
    }

    public function saveWindowHours(int $hours, User $actor): void
    {
        $this->authorization->authorize($actor, OrderPermission::ManageAmendmentSettings);
        Validator::make(['hours' => $hours], ['hours' => ['required', 'integer', Rule::in(self::WINDOW_OPTIONS)]])->validate();
        DB::transaction(function () use ($hours, $actor): void {
            $settings = OrderSetting::query()->lockForUpdate()->find(1) ?? new OrderSetting(['id' => 1]);
            $old = $settings->exists ? $settings->amendment_window_hours : self::DEFAULT_WINDOW_HOURS;
            $settings->forceFill(['id' => 1, 'amendment_window_hours' => $hours, 'updated_by_user_id' => $actor->id])->save();
            $this->activity->log('order.amendment_settings_updated', $actor, $settings, ['old_hours' => $old, 'new_hours' => $hours]);
        });
    }

    public function expiresAt(Order $order): ?CarbonInterface
    {
        $start = $this->startedAt($order);

        return $start?->copy()->addHours($this->windowHours());
    }

    public function canAmend(Order $order, User $actor): bool
    {
        if ($order->status !== OrderStatus::Reserved || $order->fulfillment()->exists()
            || ! $this->authorization->allows($actor, OrderPermission::Amend, $order)) {
            return false;
        }
        $expires = $this->expiresAt($order);

        return $expires !== null && (now()->lessThanOrEqualTo($expires)
            || $this->authorization->allows($actor, OrderPermission::AmendAfterWindow, $order));
    }

    /**
     * @param  array{external_order_number?:?string,items?:array<int,array{id:int,quantity:int,selling_price?:string}>,reason:string,idempotency_key:string}  $input
     */
    public function amend(Order $order, array $input, User $actor): OrderAmendment
    {
        $this->authorization->authorize($actor, OrderPermission::Amend, $order);
        $data = Validator::make($input, [
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
            'external_order_number' => ['nullable', 'string', 'max:255'],
            'items' => ['sometimes', 'array'],
            'items.*.id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.selling_price' => ['sometimes', 'required', 'numeric', 'min:0', 'decimal:0,2'],
        ])->validate();

        // References are allocated outside the business transaction and cannot be reused after rollback.
        $candidateReservations = $order->items()->whereIn('id', collect($data['items'] ?? [])->pluck('id'))
            ->withCount('reservations')->get()->sum('reservations_count');
        $references = [];
        for ($i = 0; $i < $candidateReservations; $i++) {
            $references[] = $this->references->nextStockMovementReference();
        }

        $normalized = collect($data['items'] ?? [])->sortBy('id')->values()->all();
        $requestHash = hash('sha256', json_encode([
            'order' => $order->id, 'external' => array_key_exists('external_order_number', $data) ? trim((string) $data['external_order_number']) : null,
            'items' => $normalized, 'reason' => trim($data['reason']),
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($order, $data, $actor, $requestHash, $references): OrderAmendment {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->authorization->authorize($actor, OrderPermission::Amend, $order);
            $existing = OrderAmendment::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                if ($existing->order_id !== $order->id || $existing->request_hash !== $requestHash) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This amendment request was already used for different changes.']);
                }

                return $existing;
            }
            if ($order->status !== OrderStatus::Reserved || $order->fulfillment()->exists()) {
                throw ValidationException::withMessages(['order' => 'Only an unfulfilled reserved Order can be amended.']);
            }
            $start = $this->startedAt($order);
            if ($start === null) {
                throw ValidationException::withMessages(['order' => 'The original reservation time is unavailable.']);
            }
            $expires = $start->copy()->addHours($this->windowHours());
            $override = now()->greaterThan($expires);
            if ($override) {
                $this->authorization->authorize($actor, OrderPermission::AmendAfterWindow, $order);
            }

            $items = $order->items()->with(['reservation', 'componentReservations', 'upgradeSelection'])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $changes = collect($data['items'] ?? [])->keyBy('id');
            if ($changes->count() !== count($data['items'] ?? []) || $changes->keys()->diff($items->keys())->isNotEmpty()) {
                throw ValidationException::withMessages(['items' => 'Every amended line must belong to this Order exactly once.']);
            }
            $invoiceExists = TaxInvoice::query()->where('source_order_id', $order->id)->exists();
            $amendment = OrderAmendment::query()->create([
                'order_id' => $order->id, 'amended_by_user_id' => $actor->id, 'reason' => trim($data['reason']),
                'window_started_at' => $start, 'window_expired_at' => $expires,
                'after_window_override' => $override, 'idempotency_key' => $data['idempotency_key'], 'request_hash' => $requestHash,
            ]);
            $movementGroup = (string) Str::uuid();
            $referenceIndex = 0;
            $totalsInput = [];
            $changedItemIds = [];

            foreach ($items as $item) {
                $change = $changes->get($item->id, []);
                $quantity = (int) ($change['quantity'] ?? $item->ordered_quantity);
                $price = isset($change['selling_price']) ? bcadd((string) $change['selling_price'], '0', 2) : (string) $item->selling_price;
                if ($invoiceExists && ($quantity !== $item->ordered_quantity || bccomp($price, (string) $item->selling_price, 2) !== 0)) {
                    throw ValidationException::withMessages(['items' => 'An issued Tax Invoice prevents quantity and selling price amendments.']);
                }
                if (bccomp($price, (string) $item->selling_price, 2) !== 0) {
                    $this->authorization->authorize($actor, OrderPermission::ViewSellingPrice, $order);
                    $this->authorization->authorize($actor, OrderPermission::EditSellingPrice, $order);
                    $this->line($amendment, $item, 'selling_price', (string) $item->selling_price, $price);
                    $changedItemIds[$item->id] = true;
                }
                if ($quantity !== $item->ordered_quantity) {
                    if (! $this->responsibilities->canAccessProduct($actor, $item->product_id, $order->marketplace_platform_id, $order->warehouse_id)) {
                        throw ValidationException::withMessages(['items' => 'A Product is outside your active Responsibility Assignments.']);
                    }
                    $reservation = $item->reservation;
                    if ($reservation?->status !== InventoryReservationStatus::Active || $reservation->quantity !== $item->ordered_quantity) {
                        throw ValidationException::withMessages(['items' => 'The Order line does not have its expected active reservation.']);
                    }
                    $delta = $quantity - $item->ordered_quantity;
                    if ($delta > 0) {
                        $stock = ProductInventory::query()->lockForUpdate()->findOrFail($reservation->product_inventory_id);
                        $attributed = DB::table('responsibility_inventory_consumptions')->where('inventory_reservation_id', $reservation->id)->value('responsibility_assignment_id');
                        if ($attributed !== null) {
                            $assignment = ResponsibilityAssignment::query()->lockForUpdate()->findOrFail($attributed);
                            if ($this->allocations->usage($assignment, lock: true)['remaining'] < $delta) {
                                throw ValidationException::withMessages(['items' => 'The requested increase exceeds the remaining Quantity Responsibility allocation.']);
                            }
                        } else {
                            $allocation = $this->allocations->lockAndAssert($actor, $stock, $order->marketplace_platform_id, $delta);
                            if ($allocation !== null) {
                                $this->allocations->recordReservation($allocation['assignment_id'], $reservation);
                            }
                        }
                    }
                    $this->inventory->adjustOrderReservation($reservation, $quantity, $actor, $references[$referenceIndex++] ?? throw new \LogicException('Missing reserved movement reference.'), (string) Str::uuid(), $movementGroup);
                    if ($item->upgradeSelection !== null) {
                        $this->adjustUpgradeSelection($item, $quantity, $actor, $amendment, $references, $referenceIndex, $movementGroup);
                    }
                    $this->line($amendment, $item, 'ordered_quantity', (string) $item->ordered_quantity, (string) $quantity);
                    $changedItemIds[$item->id] = true;
                }
                if (bccomp(bcmul((string) $quantity, $price, 2), (string) $item->discount_total, 2) < 0) {
                    throw ValidationException::withMessages(['items' => 'Line discount cannot exceed amended line value.']);
                }
                $totalsInput[] = new OrderItemData($item->product_id, $quantity, $price, (string) $item->discount_total, (string) $item->vat_rate, $item->notes);
            }

            $totals = $this->totals->calculate($totalsInput);
            foreach ($items->values() as $index => $item) {
                if (! isset($changedItemIds[$item->id])) {
                    continue;
                }
                $line = $totals['lines'][$index];
                // Deliberately bypass the generic Draft-only OrderItem model guard inside this
                // authorized, locked B1 transaction; the immutable amendment ledger records every delta.
                DB::table('order_items')->where('id', $item->id)->update([
                    'ordered_quantity' => $line['ordered_quantity'], 'selling_price' => $line['selling_price'],
                    'vat_amount' => $line['vat_amount'], 'line_total' => $line['line_total'], 'updated_at' => now(),
                ]);
            }
            if ($changedItemIds !== []) {
                $order->forceFill(array_intersect_key($totals, array_flip(['subtotal', 'discount_total', 'vat_total', 'grand_total'])))->save();
            }
            if (array_key_exists('external_order_number', $data)) {
                $external = trim((string) $data['external_order_number']) ?: null;
                if ($external !== $order->external_order_number) {
                    $hash = $this->externalIdentity->hash($order->marketplace_platform_id, $external);
                    if ($hash !== null && Order::query()->where('external_identity_hash', $hash)->where('id', '!=', $order->id)->exists()) {
                        throw ValidationException::withMessages(['external_order_number' => 'This external order number already belongs to another Order on this Platform.']);
                    }
                    $this->line($amendment, null, 'external_order_number', $order->external_order_number, $external);
                    $order->forceFill(['external_order_number' => $external, 'external_identity_hash' => $hash])->save();
                }
            }
            if (! $amendment->lines()->exists()) {
                throw ValidationException::withMessages(['order' => 'Change at least one supported Order value.']);
            }

            $this->activity->log('order.amended', $actor, $order, ['amendment_id' => $amendment->id, 'after_window_override' => $override]);

            return $amendment;
        }, 5);
    }

    private function startedAt(Order $order): ?CarbonInterface
    {
        return $order->reserved_at?->copy() ?? $order->statusEvents()->where('from_status', OrderStatus::Draft->value)
            ->where('to_status', '!=', OrderStatus::Draft->value)->orderBy('id')->first()?->created_at;
    }

    private function line(OrderAmendment $amendment, ?OrderItem $item, string $field, ?string $old, ?string $new): void
    {
        $amendment->lines()->create(['order_item_id' => $item?->id, 'field' => $field, 'old_value' => $old, 'new_value' => $new]);
    }

    /** @param list<string> $references */
    private function adjustUpgradeSelection(OrderItem $item, int $quantity, User $actor, OrderAmendment $amendment, array $references, int &$referenceIndex, string $movementGroup): void
    {
        $selection = $item->upgradeSelection;
        $snapshot = $selection->recipe_snapshot;
        $recovery = $selection->recovery_snapshot;
        foreach ($snapshot['lines'] as &$line) {
            $computed = bcmul((string) $line['quantity_per_laptop'], (string) $quantity, 4);
            if (! preg_match('/^\d+\.0000$/', $computed) || (int) $computed < 1) {
                throw ValidationException::withMessages(['items' => 'The amended quantity must produce whole upgrade component quantities.']);
            }
            $line['quantity'] = (int) $computed;
            if ($line['operation'] === 'install') {
                $reservation = $item->componentReservations->firstWhere('upgrade_recipe_line_id', (int) $line['upgrade_recipe_line_id']);
                if ($reservation?->status !== InventoryReservationStatus::Active) {
                    throw ValidationException::withMessages(['items' => 'An upgrade Component reservation is missing.']);
                }
                $this->inventory->adjustOrderReservation($reservation, (int) $computed, $actor, $references[$referenceIndex++] ?? throw new \LogicException('Missing component movement reference.'), (string) Str::uuid(), $movementGroup);
            }
        }
        unset($line);
        foreach ($recovery['lines'] as &$line) {
            $snapshotLine = collect($snapshot['lines'])->firstWhere('upgrade_recipe_line_id', $line['upgrade_recipe_line_id']);
            $line['quantity'] = $snapshotLine['quantity'];
        }
        unset($line);
        // Snapshot revisions are restricted to this B1 transaction and preserved verbatim in the
        // append-only amendment ledger; the original selection model remains immutable elsewhere.
        $this->line($amendment, $item, 'recipe_snapshot', json_encode($selection->recipe_snapshot, JSON_THROW_ON_ERROR), json_encode($snapshot, JSON_THROW_ON_ERROR));
        DB::table('order_item_upgrade_selections')->where('id', $selection->id)->update([
            'recipe_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'recovery_snapshot' => json_encode($recovery, JSON_THROW_ON_ERROR), 'updated_at' => now(),
        ]);
    }
}
