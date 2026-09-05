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
use App\Services\ActivityLogger;
use App\Services\Authorization\QuotationAuthorization;
use App\Services\Invoices\TaxInvoiceService;
use App\Services\Orders\OrderService;
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
    ) {}

    public function toOrder(Quotation $quotation, int $warehouseId, User $actor, ?string $idempotencyKey = null): Order
    {
        $this->authorization->authorize($actor, QuotationPermission::ConvertOrder, $quotation);
        $quotation->loadMissing('items');
        if ($quotation->order_id) {
            return $quotation->order;
        }
        $this->assertConvertible($quotation);
        $key = $this->reserveConversionKey($quotation, 'order_conversion_idempotency_key', $idempotencyKey);
        $items = $quotation->items->map(fn ($item) => new OrderItemData(
            productId: $item->product_id,
            quantity: $item->quantity,
            sellingPrice: (string) $item->unit_price_including_vat,
            discountTotal: (string) $item->discount_amount,
            vatRate: '0.0000',
            notes: "Converted from {$quotation->reference}",
        ))->all();
        $order = $this->orders->saveAndReserve(new SaveAndReserveOrderData(
            warehouseId: $warehouseId, platformId: null, externalOrderNumber: null,
            orderDate: today()->toDateString(), handledByEmployeeId: $quotation->salesperson_employee_id,
            notes: "Converted from {$quotation->reference}".($quotation->notes ? "\n{$quotation->notes}" : ''),
            items: $items, idempotencyKey: $key, webSalesChannel: 'other',
            customerName: $quotation->customer_name, customerPhone: $quotation->customer_phone,
            deliveryType: 'shop_pickup',
        ), $actor);

        DB::transaction(function () use ($quotation, $order, $key, $actor): void {
            $locked = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);
            if ($locked->order_id && $locked->order_id !== $order->id) {
                throw ValidationException::withMessages(['conversion' => 'This Quotation has already been converted to another Order.']);
            }
            if (! $locked->order_id) {
                $locked->forceFill(['order_id' => $order->id, 'order_conversion_idempotency_key' => $key, 'status' => QuotationStatus::Converted, 'converted_at' => now()])->save();
                $this->activity->log('quotation.converted_to_order', $actor, $locked, ['quotation_reference' => $locked->reference, 'order_reference' => $order->reference, 'actor_id' => $actor->id]);
            }
        });

        return $order;
    }

    public function toInvoice(Quotation $quotation, User $actor, ?string $idempotencyKey = null): TaxInvoice
    {
        $this->authorization->authorize($actor, QuotationPermission::ConvertInvoice, $quotation);
        $quotation->loadMissing('items');
        if ($quotation->tax_invoice_id) {
            return $quotation->taxInvoice;
        }
        $this->assertConvertible($quotation);
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
            $locked = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);
            if (filled($locked->{$column})) {
                return $locked->{$column};
            }
            $this->assertConvertible($locked);
            $key = $requested ?: (string) Str::uuid();
            $locked->forceFill([$column => $key])->save();

            return $key;
        });
    }
}
