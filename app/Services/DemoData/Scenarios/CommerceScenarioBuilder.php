<?php

namespace App\Services\DemoData\Scenarios;

use App\Actions\Inventory\PostOpeningStock;
use App\Actions\Purchases\ApprovePurchase;
use App\Actions\Purchases\CreatePurchase;
use App\DTOs\Inventory\PostOpeningStockData;
use App\DTOs\Orders\CancelOrderData;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\DTOs\Orders\WebSalesOrderData;
use App\DTOs\Purchases\ApprovePurchaseData;
use App\DTOs\Purchases\CreatePurchaseData;
use App\DTOs\Purchases\PurchaseItemData;
use App\DTOs\Purchases\PurchaseReceiptItemData;
use App\DTOs\Purchases\ReceivePurchaseData;
use App\Enums\InventoryItemType;
use App\Enums\OrderStatus;
use App\Enums\PurchaseStatus;
use App\Enums\QuotationDocumentType;
use App\Enums\QuotationStatus;
use App\Enums\WebSalesChannel;
use App\Enums\WebSalesDeliveryType;
use App\Models\Order;
use App\Models\Purchase;
use App\Models\Quotation;
use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoIdentity;
use App\Services\Orders\OrderService;
use App\Services\Orders\WebSalesService;
use App\Services\Purchases\PurchaseReceivingService;
use App\Services\Quotations\QuotationConversionService;
use App\Services\Quotations\QuotationService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use RuntimeException;

final class CommerceScenarioBuilder
{
    public function __construct(
        private readonly DemoIdentity $identity,
        private readonly PostOpeningStock $openingStock,
        private readonly CreatePurchase $createPurchase,
        private readonly ApprovePurchase $approvePurchase,
        private readonly PurchaseReceivingService $receiving,
        private readonly OrderService $orders,
        private readonly WebSalesService $webSales,
        private readonly QuotationService $quotations,
        private readonly QuotationConversionService $quotationConversions,
    ) {}

    public function build(DemoContext $context): void
    {
        $this->openingStock($context);
        $this->purchases($context);
        $converted = $this->quotations($context);
        $this->orders($context, $converted);

        $context->count('purchases', Purchase::query()->where('external_accounting_reference', 'like', 'DEMO-PO-v1-%')->count());
        $context->count('quotations', Quotation::query()->where('external_reference', 'like', 'DEMO-QUO-v1-%')->count());
        $context->count('orders', 60);
    }

    private function openingStock(DemoContext $context): void
    {
        foreach ([0 => ['6', '760.0000'], 1 => ['5', '815.0000']] as $index => [$quantity, $cost]) {
            $this->openingStock->handle(new PostOpeningStockData(
                productId: $context->products[$index]->id,
                warehouseId: $context->warehouse->id,
                availableQuantity: (int) $quantity,
                damagedQuantity: 0,
                unitCost: $cost,
                reason: $this->identity->note('Approved opening balance fixture.'),
                idempotencyKey: $this->identity->uuid('opening-stock/'.$index),
            ), $context->owner);
        }
    }

    private function purchases(DemoContext $context): void
    {
        $inventoryProducts = $context->products->take(28)->concat($context->components->map->product)->values();
        $linePool = $inventoryProducts->concat($context->products->take(14))->values();
        $lowStockProductIds = $context->products->slice(24, 4)->pluck('id')->all();

        foreach (range(1, 10) as $purchaseNumber) {
            $external = $this->identity->external('PO', $purchaseNumber);
            $purchase = Purchase::query()->where('external_accounting_reference', $external)->with(['items', 'receipts'])->first();
            $date = CarbonImmutable::today()->subDays(86 - ($purchaseNumber * 7))->setTime(10, 0);
            $items = collect(range(0, 4))->map(function (int $offset) use ($linePool, $lowStockProductIds, $purchaseNumber): PurchaseItemData {
                $product = $linePool[(($purchaseNumber - 1) * 5 + $offset) % $linePool->count()];
                $component = $product->inventory_item_type === InventoryItemType::Component;

                return new PurchaseItemData(
                    productId: $product->id,
                    orderedQuantity: $component ? 30 : (in_array($product->id, $lowStockProductIds, true) ? 2 : 12),
                    unitCost: number_format(($component ? 45 : 680) + ($purchaseNumber * 17) + ($offset * 9), 4, '.', ''),
                    vatRate: '5.00',
                    notes: $this->identity->note('Purchase line fixture.'),
                );
            })->all();

            if ($purchase === null) {
                $purchase = $this->at($date, fn (): Purchase => $this->createPurchase->handle(new CreatePurchaseData(
                    supplierId: $context->suppliers[($purchaseNumber - 1) % 5]->id,
                    warehouseId: $context->warehouse->id,
                    purchaseDate: $date->toDateString(),
                    items: $items,
                    supplierInvoiceNumber: sprintf('DEMO-SINV-%03d', $purchaseNumber),
                    supplierInvoiceDate: $date->toDateString(),
                    expectedDeliveryDate: $date->addDays(4)->toDateString(),
                    externalAccountingReference: $external,
                    notes: $this->identity->note('Purchase fixture '.$purchaseNumber.'.'),
                ), $context->owner));
            } else {
                $this->assert($purchase->notes === $this->identity->note('Purchase fixture '.$purchaseNumber.'.'), "Demo Purchase [{$external}] has an unexpected fingerprint.");
            }

            if ($purchase->status === PurchaseStatus::Draft) {
                $purchase = $this->at($date->addHours(1), fn (): Purchase => $this->approvePurchase->handle($purchase, new ApprovePurchaseData('Approved staging demonstration purchase.', true), $context->owner));
            }
            if ($purchaseNumber === 10 || $purchase->receipts()->where('idempotency_key', $this->identity->uuid('purchase-receipt/'.$purchaseNumber))->exists()) {
                continue;
            }

            $purchase->load('items');
            $receiptItems = $purchase->items->values()->map(function ($item, int $index) use ($purchaseNumber): PurchaseReceiptItemData {
                $ordered = (int) $item->ordered_quantity;
                $physical = $purchaseNumber === 8 ? max(1, intdiv($ordered, 2)) : $ordered;
                $damaged = $purchaseNumber === 3 && $index === 0 ? 1 : 0;
                $rejected = $purchaseNumber === 6 && $index === 1 ? 1 : 0;

                return new PurchaseReceiptItemData(
                    purchaseItemId: $item->id,
                    acceptedQuantity: max(0, $physical - $damaged - $rejected),
                    damagedQuantity: $damaged,
                    rejectedQuantity: $rejected,
                    notes: $this->identity->note('GRN classification fixture.'),
                );
            })->all();
            $this->at($date->addDays(3), fn () => $this->receiving->receive($purchase, new ReceivePurchaseData(
                items: $receiptItems,
                receivedAt: $date->addDays(3)->toDateTimeString(),
                idempotencyKey: $this->identity->uuid('purchase-receipt/'.$purchaseNumber),
                supplierDeliveryNote: sprintf('DEMO-DN-%03d', $purchaseNumber),
                notes: $this->identity->note('Purchase receipt fixture.'),
            ), $context->owner));
        }
    }

    /** @return array<int, Order> */
    private function quotations(DemoContext $context): array
    {
        $converted = [];
        foreach (range(1, 8) as $number) {
            $external = $this->identity->external('QUO', $number);
            $quotation = Quotation::query()->where('external_reference', $external)->first();
            $date = CarbonImmutable::today()->subDays(55 - ($number * 5))->setTime(11, 0);
            if ($quotation === null) {
                $product = $context->products[($number + 4) % 18];
                $quotation = $this->at($date, fn (): Quotation => $this->quotations->create([
                    'document_type' => $number % 3 === 0 ? QuotationDocumentType::ProformaInvoice : QuotationDocumentType::Quotation,
                    'quotation_date' => $date->toDateString(), 'valid_until' => $date->addDays(30)->toDateString(),
                    'customer_name' => sprintf('Demo Customer %03d', $number),
                    'customer_company' => 'Demo Trading Company', 'customer_phone' => '+971000000000',
                    'customer_email' => null, 'customer_address' => 'Demo address, UAE', 'customer_trn' => null,
                    'external_reference' => $external, 'notes' => $this->identity->note('Quotation fixture.'),
                    'idempotency_key' => $this->identity->uuid('quotation/'.$number),
                    'items' => [[
                        'product_id' => $product->id, 'description' => $product->name,
                        'quantity' => 1, 'unit_price_including_vat' => (string) $product->selling_price,
                        'discount_amount' => '0.00', 'vat_rate' => '5.0000',
                    ]],
                ], $context->owner));
            }

            if ($number <= 4 && $quotation->status === QuotationStatus::Draft) {
                $quotation = $this->at($date->addDay(), fn (): Quotation => $this->quotations->transition($quotation, QuotationStatus::Sent, $context->owner));
            }
            if ($number <= 2 && $quotation->status === QuotationStatus::Sent) {
                $quotation = $this->at($date->addDays(2), fn (): Quotation => $this->quotations->transition($quotation, QuotationStatus::Accepted, $context->owner));
            } elseif ($number === 3 && $quotation->status === QuotationStatus::Sent) {
                $quotation = $this->at($date->addDays(2), fn (): Quotation => $this->quotations->transition($quotation, QuotationStatus::Rejected, $context->owner, 'Demo customer selected another option.'));
            }
            if ($number <= 2) {
                $order = $this->at($date->addDays(3), fn (): Order => $this->quotationConversions->toOrder($quotation, $context->warehouse->id, $context->owner, $this->identity->uuid('quotation-order/'.$number)));
                if (! str_starts_with((string) $order->notes, 'Converted from')) {
                    throw new RuntimeException('Quotation conversion produced an unexpected Order fingerprint.');
                }
                $converted[] = $order;
            }
        }

        return $converted;
    }

    /** @param array<int, Order> $converted */
    private function orders(DemoContext $context, array $converted): void
    {
        foreach ($converted as $offset => $order) {
            $this->finishOrder($context, $order, $offset + 1, false);
        }

        foreach (range(3, 60) as $number) {
            $key = $this->identity->uuid('order/'.$number);
            $order = Order::query()->where('idempotency_key', $key)->first();
            $date = CarbonImmutable::today()->subDays(($number * 7) % 84)->setTime(14, 0);
            $items = $this->orderItems($context, $number);
            $web = $number >= 3 && $number <= 22;

            if ($order === null) {
                if ($web) {
                    $order = $this->at($date, fn (): Order => $this->webSales->createConfirmed(new WebSalesOrderData(
                        customerName: sprintf('Demo Customer %03d', $number),
                        customerPhone: '+971000000000',
                        channel: $number % 2 === 0 ? WebSalesChannel::Website : WebSalesChannel::WhatsApp,
                        deliveryType: WebSalesDeliveryType::Courier,
                        courierName: 'Demo Courier', trackingNumber: sprintf('DEMO-TRACK-%03d', $number),
                        items: $items, idempotencyKey: $key,
                        notes: $this->identity->note('Web Sale fixture '.$number.'.'),
                    ), $context->owner));
                } else {
                    $platform = $number % 3 === 0 ? $context->platforms[$number % $context->platforms->count()] : null;
                    $order = $this->at($date, fn (): Order => $this->orders->saveAndReserve(new SaveAndReserveOrderData(
                        warehouseId: $context->warehouse->id,
                        platformId: $platform?->id,
                        externalOrderNumber: $this->identity->external('ORD', $number),
                        orderDate: $date->toDateString(),
                        handledByEmployeeId: $context->employees['staff1']->id,
                        notes: $this->identity->note('Order fixture '.$number.'.'),
                        items: $items,
                        idempotencyKey: $key,
                    ), $context->owner));
                }
            }
            $this->finishOrder($context, $order, $number, $web);
        }
    }

    private function finishOrder(DemoContext $context, Order $order, int $number, bool $web): void
    {
        $date = CarbonImmutable::parse($order->order_date)->setTime(16, 0);
        if ($number <= 40 && $order->status === OrderStatus::Reserved) {
            $order = $this->at($date->addDay(), fn (): Order => $web
                ? $this->webSales->ship($order, $this->identity->uuid('order-fulfill/'.$number), $context->owner)
                : $this->orders->fulfill($order, $this->identity->uuid('order-fulfill/'.$number), $context->owner));
            if ($web && $order->delivered_at === null) {
                $this->at($date->addDays(2), fn (): Order => $this->webSales->deliver($order, $context->owner));
            }
        } elseif ($number >= 53 && $order->status === OrderStatus::Reserved) {
            $this->at($date->addHours(2), fn (): Order => $web
                ? $this->webSales->cancel($order, 'Demo cancellation and reservation release.', $this->identity->uuid('order-cancel/'.$number), $context->owner)
                : $this->orders->cancel($order, new CancelOrderData('Demo cancellation and reservation release.', $this->identity->uuid('order-cancel/'.$number)), $context->owner));
        }
    }

    /** @return array<int, OrderItemData> */
    private function orderItems(DemoContext $context, int $number): array
    {
        if ($number === 3) {
            $product = $context->products[0];

            return [
                new OrderItemData($product->id, 1, (string) $product->selling_price, notes: $this->identity->note('Base configuration.')),
                new OrderItemData($product->id, 1, '1750.00', notes: $this->identity->note('16GB / 512GB configuration.'), salesConfigurationId: $context->configurations['16_512']->id, upgradeRecipeId: $context->recipes['16_512']->id),
                new OrderItemData($product->id, 1, '2050.00', notes: $this->identity->note('32GB / 1TB configuration.'), salesConfigurationId: $context->configurations['32_1tb']->id, upgradeRecipeId: $context->recipes['32_1tb']->id),
            ];
        }
        $product = $context->products[($number - 1) % 20];

        return [new OrderItemData(
            productId: $product->id,
            quantity: 1,
            sellingPrice: (string) $product->selling_price,
            discountTotal: $number % 11 === 0 ? '25.00' : '0.00',
            vatRate: '5.0000',
            notes: $this->identity->note('Order line fixture.'),
        )];
    }

    private function at(CarbonImmutable $when, Closure $callback): mixed
    {
        $previous = CarbonImmutable::getTestNow();
        CarbonImmutable::setTestNow($when);
        Carbon::setTestNow($when);
        try {
            return $callback();
        } finally {
            CarbonImmutable::setTestNow($previous);
            Carbon::setTestNow($previous);
        }
    }

    private function assert(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }
}
