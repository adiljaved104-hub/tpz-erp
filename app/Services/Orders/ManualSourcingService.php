<?php

namespace App\Services\Orders;

use App\DTOs\Orders\OrderItemData;
use App\Enums\InventoryItemType;
use App\Enums\ManualSourcingStatus;
use App\Enums\OrderStatus;
use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Enums\WebSalesPermission;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseReceipt;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WebSalesManualSourcingLine;
use App\Models\WebSalesManualSourcingReceiptEvent;
use App\Models\WebSalesSupplierDirectDelivery;
use App\Services\ActivityLogger;
use App\Services\Authorization\WebSalesAuthorization;
use App\Services\DefaultWarehouseService;
use App\Services\ReferenceSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManualSourcingService
{
    public function __construct(private readonly ReferenceSequenceService $references, private readonly ActivityLogger $activity, private readonly WebSalesAuthorization $authorization, private readonly OrderLineFulfillmentService $lineFulfillments) {}

    /** @param array<int, OrderItemData> $items */
    public function materializeItems(array $items, User $actor): array
    {
        $reservedSkus = [];
        foreach ($items as $index => $item) {
            if ($item->sourceType === 'manual_sourced' && $item->productId < 1) {
                $reservedSkus[$index] = $this->references->nextProductSku();
            }
        }

        return DB::transaction(fn (): array => array_map(function (OrderItemData $item, int $index) use ($actor, $reservedSkus): OrderItemData {
            if ($item->sourceType !== 'manual_sourced') {
                return $item;
            }

            if ($item->productId > 0) {
                // Manual sourcing may purchase a catalog product with no
                // sellable stock. Reuse its actual SKU and immutable identity
                // rather than creating a near-duplicate Product record.
                $product = Product::query()->products()->whereKey($item->productId)
                    ->where('status', ProductStatus::Active->value)->first();
                if ($product === null) {
                    throw ValidationException::withMessages(['items' => 'Select an active catalog product for manual sourcing.']);
                }

                $warehouse = app(DefaultWarehouseService::class)->operationalDefault();
                if ($warehouse->code !== 'MAIN' || ! app(OrderResponsibilityScopeService::class)->canAccessProduct($actor, $product->id, null, $warehouse->id)) {
                    throw ValidationException::withMessages(['items' => 'This catalog product is outside your authorized Web Sales responsibility.']);
                }

                $condition = $product->condition instanceof ProductCondition
                    ? $product->condition->value : (string) $product->condition;

                return new OrderItemData(
                    $product->id, $item->quantity, $item->sellingPrice,
                    $item->discountTotal, $item->vatRate, $item->notes,
                    null, null, null,
                    'manual_sourced', $product->name,
                    (string) $product->displayBrandName(),
                    (string) $product->displayCategoryName(),
                    $product->model, $condition, $item->supplierId,
                    $item->sourceMode, $item->actualUnitCost,
                    $item->purchaseCurrency, $item->purchasedOn,
                );
            }

            if (blank($item->manualDescription) || blank($item->manualCategory) || ! in_array($item->sourceMode, ['warehouse_receipt', 'supplier_direct'], true)) {
                throw ValidationException::withMessages(['items' => 'Manual sourced lines require a description, category, and valid fulfilment mode.']);
            }
            $category = trim((string) $item->manualCategory);
            if (mb_strlen($category) > 120) {
                throw ValidationException::withMessages(['items' => 'Manual sourced product categories may not exceed 120 characters.']);
            }
            $condition = $item->manualCondition ?: ProductCondition::New->value;
            if (! in_array($condition, array_column(ProductCondition::cases(), 'value'), true)) {
                throw ValidationException::withMessages(['items' => 'Select a valid product condition.']);
            }
            $identity = hash('sha256', implode('|', [mb_strtolower(trim($item->manualDescription)), mb_strtolower(trim((string) $item->manualBrand)), mb_strtolower(trim((string) $item->manualModel)), $condition]));
            $product = Product::query()->products()->where('status', ProductStatus::Active->value)
                ->where(function ($query) use ($identity, $item, $condition): void {
                    $query->where('manual_sourcing_identity', $identity)
                        ->orWhere(function ($fallback) use ($item, $condition): void {
                            $fallback->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($item->manualDescription))])
                                ->when($item->manualModel, fn ($q) => $q->where('model', trim($item->manualModel)))
                                ->when($item->manualBrand, fn ($q) => $q->where('brand', trim($item->manualBrand)))
                                ->where('condition', $condition);
                        });
                })->lockForUpdate()->first();
            if ($product === null) {
                $product = new Product;
                $product->forceFill([
                    'sku' => $reservedSkus[$index], 'inventory_item_type' => InventoryItemType::Product,
                    'name' => trim($item->manualDescription), 'brand' => filled($item->manualBrand) ? trim($item->manualBrand) : null,
                    'category' => $category,
                    'model' => filled($item->manualModel) ? trim($item->manualModel) : null, 'condition' => $condition,
                    'warranty' => 0, 'cost_price' => null, 'selling_price' => $item->sellingPrice,
                    'description' => trim($item->manualDescription), 'status' => ProductStatus::Active, 'created_by_user_id' => $actor->id,
                    'manual_sourcing_identity' => $identity,
                ])->save();
                $this->activity->log('product.created', $actor, $product, ['source' => 'web_sales_manual_sourcing']);
            }

            return new OrderItemData($product->id, $item->quantity, $item->sellingPrice, $item->discountTotal, $item->vatRate, $item->notes, $item->salesConfigurationId, $item->upgradeRecipeId, $item->allocationSources, 'manual_sourced', $item->manualDescription, $item->manualBrand, $category, $item->manualModel, $condition, $item->supplierId, $item->sourceMode, $item->actualUnitCost, $item->purchaseCurrency, $item->purchasedOn);
        }, $items, array_keys($items)), 5);
    }

    /** @param array<int, OrderItemData> $items */
    public function recordLines(Order $order, array $items, User $actor): void
    {
        foreach ($order->items()->get()->values() as $index => $orderItem) {
            $item = $items[$index] ?? null;
            if (! $item || $item->sourceType !== 'manual_sourced') {
                continue;
            }
            WebSalesManualSourcingLine::query()->firstOrCreate(['idempotency_key' => (string) $order->id.'-'.$orderItem->id], [
                'order_id' => $order->id, 'order_item_id' => $orderItem->id, 'product_id' => $orderItem->product_id,
                'source_mode' => $item->sourceMode, 'status' => ManualSourcingStatus::PendingSourcing,
                'description' => $item->manualDescription ?: $orderItem->product_name, 'brand' => $item->manualBrand,
                'category' => $item->manualCategory, 'model' => $item->manualModel, 'condition' => $item->manualCondition,
                'selling_price_snapshot' => $item->sellingPrice, 'quantity' => $item->quantity, 'supplier_id' => $item->supplierId,
                'actual_unit_cost' => $item->actualUnitCost, 'purchase_currency' => $item->purchaseCurrency,
                'purchased_on' => $item->purchasedOn,
                'materialized_at' => now(), 'metadata' => ['created_by_user_id' => $actor->id],
            ]);
        }
    }

    /**
     * Supplier selection is optional while creating a Web Sale. Allow the
     * authorized user to provide it later, before a sourcing purchase or
     * shipment has been posted. Posted purchase provenance is immutable.
     */
    public function assignSupplier(WebSalesManualSourcingLine $line, int $supplierId, User $actor): WebSalesManualSourcingLine
    {
        return DB::transaction(function () use ($line, $supplierId, $actor): WebSalesManualSourcingLine {
            $line = WebSalesManualSourcingLine::query()->lockForUpdate()->findOrFail($line->id);
            $order = Order::query()->lockForUpdate()->findOrFail($line->order_id);
            $this->authorization->authorize($actor, WebSalesPermission::Update, $order);

            if ($order->status !== OrderStatus::PendingReview
                || ! in_array($line->source_mode, ['supplier_direct', 'warehouse_receipt'], true)
                || $line->status !== ManualSourcingStatus::PendingSourcing
                || $line->purchase_id !== null
                || $line->purchase_receipt_id !== null
                || $line->supplierDirectDeliveries()->exists()) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'The supplier cannot be changed after sourcing or shipment has started.',
                ]);
            }

            if (! Supplier::query()->active()->whereKey($supplierId)->exists()) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'Choose an active supplier.',
                ]);
            }

            $previousSupplierId = $line->supplier_id;
            if ((int) $previousSupplierId === $supplierId) {
                return $line;
            }

            $line->forceFill(['supplier_id' => $supplierId])->save();
            $this->activity->log('web_sales.manual_sourcing_supplier_updated', $actor, $line, [
                'order_id' => $order->id,
                'previous_supplier_id' => $previousSupplierId,
                'supplier_id' => $supplierId,
            ]);

            return $line->refresh();
        }, 5);
    }

    public function assertReadyForFulfilment(Order $order): void
    {
        $lines = $order->manualSourcingLines()->lockForUpdate()->get();
        foreach ($lines as $line) {
            if ($line->source_mode === 'supplier_direct') {
                if (! in_array($line->status, [ManualSourcingStatus::SupplierDirectFulfilled, ManualSourcingStatus::ReadyForAllocation], true)) {
                    throw ValidationException::withMessages(['order' => 'Supplier-direct sourcing must be confirmed before fulfilment.']);
                }

                continue;
            }
            if ($line->status !== ManualSourcingStatus::ReadyForAllocation) {
                throw ValidationException::withMessages(['order' => 'Manual sourced stock is not receipt-confirmed and ready for allocation.']);
            }
        }
    }

    public function confirmReceipt(WebSalesManualSourcingLine $line, PurchaseReceipt $receipt, User $actor, ?string $idempotencyKey = null): WebSalesManualSourcingLine
    {
        $this->authorization->authorize($actor, WebSalesPermission::Update, $line->order);

        return DB::transaction(function () use ($line, $receipt, $actor, $idempotencyKey): WebSalesManualSourcingLine {
            $line = WebSalesManualSourcingLine::query()->lockForUpdate()->findOrFail($line->id);
            if ($idempotencyKey && ($existingEvent = WebSalesManualSourcingReceiptEvent::query()->where('idempotency_key', $idempotencyKey)->first())) {
                if ($existingEvent->line_id !== $line->id || $existingEvent->purchase_receipt_id !== $receipt->id) {
                    throw ValidationException::withMessages(['receipt' => 'The receipt idempotency key belongs to another sourcing line.']);
                }

                return $line->refresh();
            }
            if ($line->source_mode !== 'warehouse_receipt' || in_array($line->status, [ManualSourcingStatus::Fulfilled, ManualSourcingStatus::Cancelled], true)) {
                throw ValidationException::withMessages(['line' => 'This sourcing line cannot accept another receipt.']);
            }
            if ($line->purchase_id !== null && (int) $line->purchase_id !== (int) $receipt->purchase_id) {
                throw ValidationException::withMessages(['receipt' => 'The GRN is not linked to this sourcing line purchase.']);
            }
            $received = $receipt->items()->where('product_id', $line->product_id)->sum('accepted_quantity');
            $alreadyLinked = (int) $line->receiptEvents()->sum('quantity');
            $available = (int) $received - $alreadyLinked;
            if ($available < 1) {
                throw ValidationException::withMessages(['receipt' => 'This GRN has no new accepted quantity for the sourcing line.']);
            }
            $quantity = min($available, (int) $line->quantity - $alreadyLinked);
            if ($quantity < 1) {
                return $line->refresh();
            }
            $key = $idempotencyKey ?: (string) str()->uuid();
            $event = WebSalesManualSourcingReceiptEvent::query()->where('line_id', $line->id)->where('purchase_receipt_id', $receipt->id)->first()
                ?: WebSalesManualSourcingReceiptEvent::query()->firstOrCreate(['idempotency_key' => $key], [
                    'line_id' => $line->id, 'purchase_receipt_id' => $receipt->id, 'quantity' => $quantity,
                    'confirmed_by_user_id' => $actor->id, 'confirmed_at' => now(),
                ]);
            if ($event->line_id !== $line->id || $event->purchase_receipt_id !== $receipt->id) {
                throw ValidationException::withMessages(['receipt' => 'The receipt idempotency key belongs to another sourcing line.']);
            }
            $total = (int) $line->receiptEvents()->sum('quantity');
            $line->forceFill([
                'purchase_id' => $receipt->purchase_id,
                'purchase_receipt_id' => $receipt->id,
                'status' => $total >= (int) $line->quantity ? ManualSourcingStatus::ReadyForAllocation : ManualSourcingStatus::ReceiptPending,
                'receipt_confirmed_at' => $total >= (int) $line->quantity ? now() : $line->receipt_confirmed_at,
                'metadata' => array_merge($line->metadata ?? [], ['receipt_confirmed_by' => $actor->id, 'received_quantity' => $total]),
            ])->save();
            $this->activity->log('web_sales.manual_sourcing_receipt_confirmed', $actor, $line, ['receipt_id' => $receipt->id, 'quantity' => $quantity, 'received_total' => $total]);

            return $line->refresh();
        }, 5);
    }

    public function markSupplierDirectFulfilled(WebSalesManualSourcingLine $line, User $actor, int $quantity, ?string $supplierReference = null, ?array $evidence = null, ?string $idempotencyKey = null): WebSalesManualSourcingLine
    {
        $this->authorization->authorize($actor, WebSalesPermission::Update, $line->order);
        if ($line->source_mode !== 'supplier_direct') {
            throw ValidationException::withMessages(['line' => 'Only supplier-direct lines can be confirmed without a warehouse receipt.']);
        }
        if ($quantity < 1) {
            throw ValidationException::withMessages(['quantity' => 'Delivered quantity must be positive.']);
        }

        return DB::transaction(function () use ($line, $actor, $quantity, $supplierReference, $evidence, $idempotencyKey): WebSalesManualSourcingLine {
            $line = WebSalesManualSourcingLine::query()->lockForUpdate()->findOrFail($line->id);
            $already = (int) $line->supplierDirectDeliveries()->sum('quantity');
            $remaining = (int) $line->quantity - $already;
            if ($remaining < 1) {
                return $line->refresh();
            }
            $quantity = min($quantity, $remaining);
            $key = $idempotencyKey ?: (string) str()->uuid();
            $event = WebSalesSupplierDirectDelivery::query()->firstOrCreate(['idempotency_key' => $key], [
                'line_id' => $line->id, 'quantity' => $quantity, 'supplier_reference' => $supplierReference,
                'evidence' => $evidence, 'confirmed_by_user_id' => $actor->id, 'confirmed_at' => now(),
            ]);
            $this->lineFulfillments->recordSupplierDirectDelivery($line->orderItem, $quantity, $actor, $key, $supplierReference, $evidence);
            if ($event->line_id !== $line->id) {
                throw ValidationException::withMessages(['delivery' => 'The delivery idempotency key belongs to another line.']);
            }
            $total = (int) $line->supplierDirectDeliveries()->sum('quantity');
            $line->forceFill(['status' => $total >= (int) $line->quantity ? ManualSourcingStatus::SupplierDirectFulfilled : ManualSourcingStatus::ReceiptPending, 'fulfilled_at' => $total >= (int) $line->quantity ? now() : null, 'metadata' => array_merge($line->metadata ?? [], ['supplier_direct_confirmed_by' => $actor->id, 'delivered_quantity' => $total])])->save();
            $this->activity->log('web_sales.manual_sourcing_supplier_direct_confirmed', $actor, $line, ['quantity' => $quantity, 'delivered_total' => $total, 'supplier_reference' => $supplierReference]);

            return $line->refresh();
        }, 5);
    }

    public function markFulfilled(Order $order, User $actor): void
    {
        foreach ($order->manualSourcingLines()->where('status', '!=', ManualSourcingStatus::Fulfilled->value)->lockForUpdate()->get() as $line) {
            $line->forceFill(['status' => ManualSourcingStatus::Fulfilled, 'fulfilled_at' => now()])->save();
            $this->activity->log('web_sales.manual_sourcing_fulfilled', $actor, $line, ['order_id' => $order->id, 'quantity' => $line->quantity]);
        }
    }
}
