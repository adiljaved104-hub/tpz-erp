<?php

namespace App\Services\Orders;

use App\DTOs\Orders\OrderItemData;
use App\Enums\InventoryItemType;
use App\Enums\ManualSourcingStatus;
use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\WebSalesManualSourcingLine;
use App\Services\ActivityLogger;
use App\Services\ReferenceSequenceService;
use Illuminate\Validation\ValidationException;

class ManualSourcingService
{
    public function __construct(private readonly ReferenceSequenceService $references, private readonly ActivityLogger $activity) {}

    /** @param array<int, OrderItemData> $items */
    public function materializeItems(array $items, User $actor): array
    {
        return array_map(function (OrderItemData $item) use ($actor): OrderItemData {
            if ($item->sourceType !== 'manual_sourced') return $item;
            if (blank($item->manualDescription) || ! in_array($item->sourceMode, ['warehouse_receipt', 'supplier_direct'], true)) {
                throw ValidationException::withMessages(['items' => 'Manual sourced lines require a description and valid fulfilment mode.']);
            }
            $condition = $item->manualCondition ?: ProductCondition::New->value;
            if (! in_array($condition, array_column(ProductCondition::cases(), 'value'), true)) {
                throw ValidationException::withMessages(['items' => 'Select a valid product condition.']);
            }
            $product = Product::query()->products()->where('status', ProductStatus::Active->value)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($item->manualDescription))])
                ->when($item->manualModel, fn ($q) => $q->where('model', trim($item->manualModel)))
                ->when($item->manualBrand, fn ($q) => $q->where('brand', trim($item->manualBrand)))
                ->where('condition', $condition)->lockForUpdate()->first();
            if ($product === null) {
                $product = new Product;
                $product->forceFill([
                    'sku' => $this->references->nextProductSku(), 'inventory_item_type' => InventoryItemType::Product,
                    'name' => trim($item->manualDescription), 'brand' => filled($item->manualBrand) ? trim($item->manualBrand) : null,
                    'category' => filled($item->manualCategory) ? trim($item->manualCategory) : null,
                    'model' => filled($item->manualModel) ? trim($item->manualModel) : null, 'condition' => $condition,
                    'warranty' => 0, 'cost_price' => null, 'selling_price' => $item->sellingPrice,
                    'description' => trim($item->manualDescription), 'status' => ProductStatus::Active, 'created_by_user_id' => $actor->id,
                ])->save();
                $this->activity->log('product.created', $actor, $product, ['source' => 'web_sales_manual_sourcing']);
            }
            return new OrderItemData($product->id, $item->quantity, $item->sellingPrice, $item->discountTotal, $item->vatRate, $item->notes, $item->salesConfigurationId, $item->upgradeRecipeId, $item->allocationSources, 'manual_sourced', $item->manualDescription, $item->manualBrand, $item->manualCategory, $item->manualModel, $condition, $item->supplierId, $item->sourceMode);
        }, $items);
    }

    /** @param array<int, OrderItemData> $items */
    public function recordLines(Order $order, array $items, User $actor): void
    {
        foreach ($order->items()->get()->values() as $index => $orderItem) {
            $item = $items[$index] ?? null;
            if (! $item || $item->sourceType !== 'manual_sourced') continue;
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
}
