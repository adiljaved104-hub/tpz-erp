<?php

namespace App\Services\Orders;

use App\DTOs\Orders\OrderItemData;
use App\Enums\InventoryItemType;
use App\Enums\ManualSourcingStatus;
use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseReceipt;
use App\Models\User;
use App\Models\WebSalesManualSourcingLine;
use App\Services\ActivityLogger;
use App\Services\ReferenceSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManualSourcingService
{
    public function __construct(private readonly ReferenceSequenceService $references, private readonly ActivityLogger $activity) {}

    /** @param array<int, OrderItemData> $items */
    public function materializeItems(array $items, User $actor): array
    {
        return DB::transaction(fn (): array => array_map(function (OrderItemData $item) use ($actor): OrderItemData {
            if ($item->sourceType !== 'manual_sourced') {
                return $item;
            }
            if (blank($item->manualDescription) || ! in_array($item->sourceMode, ['warehouse_receipt', 'supplier_direct'], true)) {
                throw ValidationException::withMessages(['items' => 'Manual sourced lines require a description and valid fulfilment mode.']);
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
                    'sku' => $this->references->nextProductSku(), 'inventory_item_type' => InventoryItemType::Product,
                    'name' => trim($item->manualDescription), 'brand' => filled($item->manualBrand) ? trim($item->manualBrand) : null,
                    'category' => filled($item->manualCategory) ? trim($item->manualCategory) : null,
                    'model' => filled($item->manualModel) ? trim($item->manualModel) : null, 'condition' => $condition,
                    'warranty' => 0, 'cost_price' => null, 'selling_price' => $item->sellingPrice,
                    'description' => trim($item->manualDescription), 'status' => ProductStatus::Active, 'created_by_user_id' => $actor->id,
                    'manual_sourcing_identity' => $identity,
                ])->save();
                $this->activity->log('product.created', $actor, $product, ['source' => 'web_sales_manual_sourcing']);
            }

            return new OrderItemData($product->id, $item->quantity, $item->sellingPrice, $item->discountTotal, $item->vatRate, $item->notes, $item->salesConfigurationId, $item->upgradeRecipeId, $item->allocationSources, 'manual_sourced', $item->manualDescription, $item->manualBrand, $item->manualCategory, $item->manualModel, $condition, $item->supplierId, $item->sourceMode);
        }, $items), 5);
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
                'materialized_at' => now(), 'metadata' => ['created_by_user_id' => $actor->id],
            ]);
        }
    }

    public function assertReadyForFulfilment(Order $order): void
    {
        $lines = $order->manualSourcingLines()->lockForUpdate()->get();
        foreach ($lines as $line) {
            if ($line->source_mode === 'supplier_direct') {
                if ($line->status !== ManualSourcingStatus::SupplierDirectFulfilled) {
                    throw ValidationException::withMessages(['order' => 'Supplier-direct sourcing must be confirmed before fulfilment.']);
                }

                continue;
            }
            if ($line->status !== ManualSourcingStatus::ReadyForAllocation) {
                throw ValidationException::withMessages(['order' => 'Manual sourced stock is not receipt-confirmed and ready for allocation.']);
            }
        }
    }

    public function confirmReceipt(WebSalesManualSourcingLine $line, PurchaseReceipt $receipt, User $actor): WebSalesManualSourcingLine
    {
        return DB::transaction(function () use ($line, $receipt, $actor): WebSalesManualSourcingLine {
            $line = WebSalesManualSourcingLine::query()->lockForUpdate()->findOrFail($line->id);
            if ($line->source_mode !== 'warehouse_receipt' || $line->status === ManualSourcingStatus::Fulfilled) {
                throw ValidationException::withMessages(['line' => 'This sourcing line cannot accept another receipt.']);
            }
            $received = $receipt->items()->where('product_id', $line->product_id)->sum('accepted_quantity');
            if ((int) $received < (int) $line->quantity) {
                throw ValidationException::withMessages(['receipt' => 'The confirmed GRN does not contain the full sourced quantity.']);
            }
            $line->forceFill([
                'purchase_id' => $receipt->purchase_id,
                'purchase_receipt_id' => $receipt->id,
                'status' => ManualSourcingStatus::ReadyForAllocation,
                'receipt_confirmed_at' => now(),
                'metadata' => array_merge($line->metadata ?? [], ['receipt_confirmed_by' => $actor->id]),
            ])->save();
            $this->activity->log('web_sales.manual_sourcing_receipt_confirmed', $actor, $line, ['receipt_id' => $receipt->id, 'quantity' => $line->quantity]);

            return $line->refresh();
        }, 5);
    }

    public function markSupplierDirectFulfilled(WebSalesManualSourcingLine $line, User $actor): WebSalesManualSourcingLine
    {
        if ($line->source_mode !== 'supplier_direct') {
            throw ValidationException::withMessages(['line' => 'Only supplier-direct lines can be confirmed without a warehouse receipt.']);
        }
        $line->forceFill(['status' => ManualSourcingStatus::SupplierDirectFulfilled, 'fulfilled_at' => now(), 'metadata' => array_merge($line->metadata ?? [], ['supplier_direct_confirmed_by' => $actor->id])])->save();
        $this->activity->log('web_sales.manual_sourcing_supplier_direct_confirmed', $actor, $line, ['quantity' => $line->quantity]);

        return $line->refresh();
    }

    public function markFulfilled(Order $order, User $actor): void
    {
        foreach ($order->manualSourcingLines()->where('status', '!=', ManualSourcingStatus::Fulfilled->value)->lockForUpdate()->get() as $line) {
            $line->forceFill(['status' => ManualSourcingStatus::Fulfilled, 'fulfilled_at' => now()])->save();
            $this->activity->log('web_sales.manual_sourcing_fulfilled', $actor, $line, ['order_id' => $order->id, 'quantity' => $line->quantity]);
        }
    }
}
