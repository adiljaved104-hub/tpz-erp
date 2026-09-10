<?php

namespace App\Services\Quotations;

use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\Enums\QuotationPermission;
use App\Enums\QuotationStatus;
use App\Models\Order;
use App\Models\Quotation;
use App\Models\TaxInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ActivityLogger;
use App\Services\Authorization\QuotationAuthorization;
use App\Services\Invoices\TaxInvoiceService;
use App\Services\Orders\OrderFulfillmentLocationService;
use App\Services\Orders\OrderService;
use App\Services\ReferenceSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QuotationConversionService
{
    public function __construct(
        private readonly QuotationAuthorization $authorization,
        private readonly OrderService $orders,
        private readonly TaxInvoiceService $invoices,
        private readonly ActivityLogger $activity,
        private readonly QuotationSourcingService $sourcing,
        private readonly QuotationManualProductMaterializer $manualProducts,
        private readonly ReferenceSequenceService $references,
    ) {}

    public function toOrder(Quotation $quotation, int $warehouseId, User $actor, ?string $idempotencyKey = null, ?string $customerPhone = null): Order
    {
        $this->authorization->authorize($actor, QuotationPermission::ConvertOrder, $quotation);
        $quotation = $quotation->fresh(['items', 'order']);
        if ($quotation->order_id) {
            return $quotation->order;
        }
        $this->assertConvertible($quotation);
        if ($quotation->warehouse_id !== null && (int) $quotation->warehouse_id !== $warehouseId) {
            throw ValidationException::withMessages(['warehouse_id' => 'Use the Quotation Fulfilment Warehouse.']);
        }
        $key = $this->reserveConversionKey($quotation, 'order_conversion_idempotency_key', $idempotencyKey);
        $orderDate = today()->toDateString();
        $preparedReferences = $this->orders->preallocatePlainReservationReferences(
            (int) substr($orderDate, 0, 4),
            $quotation->items->count(),
            $actor,
        );
        $movementReferences = [];
        $manualProductSkus = [];
        foreach ($quotation->items as $item) {
            $movementReferences[$item->id] = $this->references->nextStockMovementReference();
            if ($item->product_id === null && $item->materialized_product_id === null) {
                $manualProductSkus[$item->id] = $this->references->nextProductSku();
            }
        }

        return DB::transaction(function () use ($quotation, $warehouseId, $actor, $key, $orderDate, $preparedReferences, $movementReferences, $manualProductSkus, $customerPhone): Order {
            $locked = $this->lockQuotation($quotation);
            $this->authorization->authorize($actor, QuotationPermission::ConvertOrder, $locked);
            if ($locked->order_id) {
                return $locked->order;
            }
            $this->assertConvertible($locked);
            $locked->setRelation('items', $locked->items()->lockForUpdate()->get());
            $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($warehouseId);
            app(OrderFulfillmentLocationService::class)->assertSelectable($warehouse, null);
            $this->manualProducts->materialize($locked, $actor, $manualProductSkus);
            $data = new SaveAndReserveOrderData(
                warehouseId: $warehouseId, platformId: null, externalOrderNumber: null,
                orderDate: $orderDate, handledByEmployeeId: $locked->salesperson_employee_id,
                notes: "Converted from {$locked->reference}".($locked->notes ? "\n{$locked->notes}" : ''),
                items: $locked->items->map(fn ($item) => new OrderItemData(
                    productId: $item->resolvedProductId(), quantity: $item->quantity,
                    sellingPrice: (string) $item->unit_price_including_vat,
                    discountTotal: (string) $item->discount_amount, vatRate: '0.0000',
                    notes: "Converted from {$locked->reference}",
                ))->all(),
                idempotencyKey: $key, webSalesChannel: 'other',
                customerName: $locked->customer_name, customerPhone: $customerPhone ?? $locked->customer_phone,
                deliveryType: 'shop_pickup',
            );
            $prepared = $this->orders->preparePlainReservation($data, $actor, $preparedReferences);
            $shortfalls = $this->sourcing->lockedShortfalls($locked, $warehouseId, $actor);
            $order = $this->orders->postPreparedReservation($prepared, $actor,
                fn (Order $order) => $this->sourcing->post($locked, $order, $shortfalls, $movementReferences, $actor));
            $locked->forceFill([
                'order_id' => $order->id, 'order_conversion_idempotency_key' => $key,
                'status' => QuotationStatus::Converted, 'converted_at' => now(),
            ])->save();
            $this->activity->log('quotation.converted_to_order', $actor, $locked, [
                'quotation_reference' => $locked->reference, 'order_reference' => $order->reference, 'actor_id' => $actor->id,
            ]);

            return $order;
        }, 5);
    }

    public function toInvoice(Quotation $quotation, User $actor, ?string $idempotencyKey = null): TaxInvoice
    {
        $this->authorization->authorize($actor, QuotationPermission::ConvertInvoice, $quotation);
        $quotation->loadMissing('items');
        if ($quotation->tax_invoice_id) {
            return $quotation->taxInvoice;
        }
        $this->assertConvertible($quotation);
        if ($this->sourcing->requiresOrderConversion($quotation)) {
            throw ValidationException::withMessages([
                'conversion' => 'A sourced Quotation must be converted to an Order so inventory receipt and reservation are recorded.',
            ]);
        }
        $key = $this->reserveConversionKey($quotation, 'invoice_conversion_idempotency_key', $idempotencyKey);
        $invoice = $this->invoices->create([
            'customer_name' => $quotation->customer_name,
            'customer_address' => $quotation->customer_address ?: 'Not provided',
            'customer_trn' => $quotation->customer_trn,
            'order_reference' => $quotation->order?->reference ?? $quotation->reference,
            'invoice_date' => today()->toDateString(), 'idempotency_key' => $key,
            'items' => $quotation->items->map(fn ($item) => [
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price_including_vat' => bcadd(bcdiv((string) $item->total_including_vat, (string) $item->quantity, 4), '0.005', 2),
            ])->all(),
        ], $actor);

        DB::transaction(function () use ($quotation, $invoice, $key, $actor): void {
            $locked = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);
            if ($locked->tax_invoice_id && $locked->tax_invoice_id !== $invoice->id) {
                throw ValidationException::withMessages(['conversion' => 'This Quotation has already been converted to another Invoice.']);
            }
            if (! $locked->tax_invoice_id) {
                $locked->forceFill(['tax_invoice_id' => $invoice->id, 'invoice_conversion_idempotency_key' => $key, 'status' => QuotationStatus::Converted, 'converted_at' => now()])->save();
                $this->activity->log('quotation.converted_to_invoice', $actor, $locked, ['quotation_reference' => $locked->reference, 'invoice_reference' => $invoice->invoice_number, 'actor_id' => $actor->id]);
            }
        });

        return $invoice;
    }

    private function assertConvertible(Quotation $quotation): void
    {
        if ($quotation->effectiveStatus() !== QuotationStatus::Accepted) {
            throw ValidationException::withMessages(['status' => 'Only an accepted, unexpired Quotation can be converted.']);
        }
    }

    private function reserveConversionKey(Quotation $quotation, string $column, ?string $requested): string
    {
        return DB::transaction(function () use ($quotation, $column, $requested): string {
            $locked = $this->lockQuotation($quotation);
            if (filled($locked->{$column})) {
                return $locked->{$column};
            }
            $this->assertConvertible($locked);
            $key = $requested ?: (string) Str::uuid();
            $locked->forceFill([$column => $key])->save();

            return $key;
        }, 5);
    }

    private function lockQuotation(Quotation $quotation): Quotation
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Quotation locking requires an active transaction.');
        }
        if (DB::getDriverName() === 'sqlite') {
            // SQLite ignores FOR UPDATE. Acquire its writer lock BEFORE reading so two
            // deferred transactions cannot deadlock while upgrading their read locks.
            // No commercial field, timestamp, or workflow value is changed.
            DB::table('quotations')->where('id', $quotation->id)->update(['status' => DB::raw('status')]);
        }

        return Quotation::query()->lockForUpdate()->findOrFail($quotation->id);
    }
}
