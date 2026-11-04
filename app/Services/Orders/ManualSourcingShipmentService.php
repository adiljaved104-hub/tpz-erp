<?php

namespace App\Services\Orders;

use App\DTOs\Purchases\CreatePurchaseData;
use App\DTOs\Purchases\PurchaseItemData;
use App\DTOs\Purchases\PurchaseReceiptItemData;
use App\DTOs\Purchases\ReceivePurchaseData;
use App\Enums\ManualSourcingStatus;
use App\Enums\OrderPermission;
use App\Enums\OrderStatus;
use App\Enums\PurchaseEntryType;
use App\Enums\PurchaseStatus;
use App\Enums\WebSalesPermission;
use App\Exceptions\InvalidOrderTransitionException;
use App\Models\Order;
use App\Models\Purchase;
use App\Models\User;
use App\Models\WebSalesManualSourcingLine;
use App\Services\ActivityLogger;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\WebSalesAuthorization;
use App\Services\Purchases\PurchaseDocumentService;
use App\Services\Purchases\PurchaseReceiptPostingService;
use App\Services\ReferenceSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Finalizes an already-physically-purchased, supplier-to-customer Web Sale.
 * Creates an actual Purchase and a direct-fulfillment GRN; *never* increases
 * physical warehouse balances. Virtual stock events document receipt and
 * immediate consumption for audit and traceability.
 */
class ManualSourcingShipmentService
{
    public function __construct(
        private readonly WebSalesAuthorization $webAuthorization,
        private readonly OrderAuthorization $orderAuthorization,
        private readonly OrderResponsibilityScopeService $scope,
        private readonly PurchaseDocumentService $documents,
        private readonly PurchaseReceiptPostingService $receiptPosting,
        private readonly ReferenceSequenceService $references,
        private readonly OrderService $orders,
        private readonly ManualSourcingService $manualSourcing,
        private readonly OrderLineFulfillmentService $fulfillmentLedger,
        private readonly ActivityLogger $activity,
    ) {}

    public function ship(Order $order, string $shipmentKey, User $actor): Order
    {
        Validator::make(['key' => $shipmentKey], ['key' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($order, $shipmentKey, $actor): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->webAuthorization->authorize($actor, WebSalesPermission::Update, $order);
            $this->orderAuthorization->authorize($actor, OrderPermission::Fulfill, $order);

            $lines = $order->manualSourcingLines()->orderBy('id')->lockForUpdate()->get();
            $automatic = $lines->filter(fn (WebSalesManualSourcingLine $line): bool => (bool) $line->auto_purchase_on_ship);
            if ($automatic->isEmpty() || $automatic->count() !== $lines->count()) {
                throw ValidationException::withMessages(['order' => 'This order includes a legacy sourcing workflow. It cannot be automatically posted.']);
            }

            if ($order->status === OrderStatus::Fulfilled) {
                if ($automatic->every(fn (WebSalesManualSourcingLine $line): bool => $line->purchase_id !== null && $line->purchase_receipt_id !== null && $line->shipment_posted_at !== null)) {
                    return $order; // Repeated click with any new request key is idempotent.
                }
                throw ValidationException::withMessages(['order' => 'Shipment is incomplete and requires manual reconciliation.']);
            }

            if ($order->status !== OrderStatus::PendingReview) {
                throw new InvalidOrderTransitionException('Only a confirmed manual-sourcing Web Sale can be automatically shipped.');
            }

            // Validate *all* manual lines before starting any purchase posting.
            foreach ($automatic as $line) {
                $this->assertShipReady($line, $order, $actor);
            }

            foreach ($automatic as $line) {
                $this->recordPurchaseAndDirectGrn($line, $order, $shipmentKey, $actor);
            }

            // Existing physical-stock order lines are reserved and consumed by OrderService.
            // Auto-purchased lines are excluded from physical stock reservations.
            $reserved = $this->orders->reserveDraft($order, $actor);
            $shipped = $this->orders->fulfill($reserved, $shipmentKey, $actor);

            foreach ($automatic as $line) {
                $this->fulfillmentLedger->recordManualShipment($line->refresh(), $actor);
            }
            $this->manualSourcing->markFulfilled($shipped, $actor);
            $this->activity->log('web_sales.manual_sourcing_auto_shipped', $actor, $shipped, [
                'order_id' => $shipped->id,
                'manual_line_count' => $automatic->count(),
                'shipment_idempotency_key' => $shipmentKey,
                'custody' => 'supplier_to_customer',
            ]);

            return $shipped->refresh();
        }, 5);
    }

    private function assertShipReady(WebSalesManualSourcingLine $line, Order $order, User $actor): void
    {
        if ($line->source_mode !== 'supplier_direct' || $line->status !== ManualSourcingStatus::PendingSourcing
            || $line->purchase_id !== null || $line->purchase_receipt_id !== null || $line->shipment_posted_at !== null) {
            throw ValidationException::withMessages(['items' => 'Manual sourcing line already has a purchase/GRN or cannot be shipped.']);
        }
        Validator::make([
            'supplier_id' => $line->supplier_id,
            'supplier_unit_cost' => $line->supplier_unit_cost,
            'supplier_currency' => $line->supplier_currency,
            'fx_rate_to_aed' => $line->fx_rate_to_aed,
            'inventory_unit_cost_aed' => $line->inventory_unit_cost_aed,
            'purchased_on' => $line->purchased_on?->format('Y-m-d'),
        ], [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'supplier_unit_cost' => ['required', 'numeric', 'gt:0'],
            'supplier_currency' => ['required', 'in:AED,USD,EUR,GBP,SAR'],
            'fx_rate_to_aed' => ['required', 'numeric', 'gt:0'],
            'inventory_unit_cost_aed' => ['required', 'numeric', 'gt:0'],
            'purchased_on' => ['required', 'date', 'before_or_equal:today'],
        ])->validate();
        if ($line->supplier_currency === 'AED' && bccomp((string) $line->fx_rate_to_aed, '1', 8) !== 0) {
            throw ValidationException::withMessages(['items' => 'AED supplier purchases require an FX rate of 1.']);
        }
        if ((int) $line->order_id !== $order->id
            || (int) $line->quantity !== (int) $line->orderItem->ordered_quantity
            || ! $line->supplier()->where('status', true)->exists()
            || ! $this->scope->canReceiveProduct($actor, (int) $line->product_id, (int) $order->warehouse_id)) {
            throw ValidationException::withMessages(['items' => 'Supplier, purchased quantity or employee responsibility scope is invalid.']);
        }
    }

    private function recordPurchaseAndDirectGrn(WebSalesManualSourcingLine $line, Order $order, string $shipmentKey, User $actor): void
    {
        $quantity = (int) $line->quantity;
        $unitAed = (string) $line->inventory_unit_cost_aed;
        $date = $line->purchased_on->format('Y-m-d');

        $prepared = $this->documents->prepare(new CreatePurchaseData(
            supplierId: (int) $line->supplier_id,
            warehouseId: (int) $order->warehouse_id,
            purchaseDate: $date,
            items: [new PurchaseItemData((int) $line->product_id, $quantity, $unitAed)],
            externalAccountingReference: 'WEB-SALE-'.$order->reference.'-LINE-'.$line->id,
            notes: 'Actual on-the-spot supplier purchase; supplier-to-customer shipment. No physical warehouse receipt.',
        ), actor: $actor, receivingScope: true);

        $purchase = Purchase::query()->create(array_merge($prepared['header'], [
            'reference' => $this->references->nextPurchaseReference(),
            'entry_type' => PurchaseEntryType::ManualDirect,
            'status' => PurchaseStatus::Approved,
            'created_by_user_id' => $actor->id,
            'approved_by_user_id' => $actor->id,
            'approved_at' => now(),
            'approval_reason' => 'Automatic retrospective record for already purchased, customer-direct manual sourcing.',
            'self_approved' => true,
            'handled_by_employee_id' => $order->handled_by_employee_id,
            'manual_sourcing_line_id' => $line->id,
        ]));
        $purchase->items()->createMany($prepared['lines']);
        $purchaseItem = $purchase->items()->firstOrFail();

        $receipt = $this->receiptPosting->post(
            $purchase,
            new ReceivePurchaseData(
                items: [new PurchaseReceiptItemData($purchaseItem->id, $quantity, 0, 0)],
                receivedAt: now()->toDateTimeString(),
                idempotencyKey: self::stableKey('direct-grn:'.$line->id),
                notes: 'Supplier-to-customer direct fulfillment; GRN documents purchase without warehouse custody.',
            ),
            $actor,
            $this->references->nextPurchaseReceiptReference(),
            movementReferences: [],
            directFulfillment: true,
            sourceOrderItemId: (int) $line->order_item_id,
        );

        $receiptItem = $receipt->items()->firstOrFail();
        foreach ([
            'direct_received' => $quantity,
            'direct_shipped' => -$quantity,
        ] as $event => $delta) {
            DB::table('direct_fulfillment_stock_events')->insert([
                'order_item_id' => $line->order_item_id,
                'purchase_receipt_item_id' => $receiptItem->id,
                'event_type' => $event,
                'quantity_delta' => $delta,
                'unit_cost_aed' => $unitAed,
                'recorded_by_user_id' => $actor->id,
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $line->forceFill([
            'purchase_id' => $purchase->id,
            'purchase_receipt_id' => $receipt->id,
            'status' => ManualSourcingStatus::SupplierDirectFulfilled,
            'receipt_confirmed_at' => now(),
            'shipment_idempotency_key' => $shipmentKey,
            'shipment_posted_at' => now(),
            'metadata' => array_merge($line->metadata ?? [], ['auto_posted_by' => $actor->id, 'physical_warehouse_custody' => false]),
        ])->save();

        $this->activity->log('purchase.created', $actor, $purchase, [
            'purchase_reference' => $purchase->reference,
            'source' => 'web_sales_manual_sourcing',
            'order_reference' => $order->reference,
            'fulfillment_type' => 'supplier_customer_direct',
            'grn_reference' => $receipt->reference,
            'supplier_currency' => $line->supplier_currency,
            'supplier_unit_cost' => $line->supplier_unit_cost,
            'fx_rate_to_aed' => $line->fx_rate_to_aed,
        ]);
    }

    private static function stableKey(string $seed): string
    {
        $hex = substr(hash('sha256', $seed), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}
