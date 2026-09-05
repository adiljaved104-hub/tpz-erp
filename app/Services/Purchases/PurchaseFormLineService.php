<?php

namespace App\Services\Purchases;

use App\DTOs\Purchases\PurchaseProductContext;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Support\Str;

class PurchaseFormLineService
{
    public function __construct(private readonly PurchaseProductContextService $contexts) {}

    /**
     * @param  array<string|int, array<string, mixed>>  $lines
     * @param  array<int, int|string>  $productIds
     * @return array<string|int, array<string, mixed>>
     */
    public function addProducts(array $lines, array $productIds, int $warehouseId, User $actor, ?Purchase $purchase = null): array
    {
        $existingIds = collect($lines)->pluck('product_id')->filter()->map(fn ($id): int => (int) $id);
        $newIds = collect($productIds)->map(fn ($id): int => (int) $id)->filter()->unique()
            ->reject(fn (int $id): bool => $existingIds->contains($id));
        $products = Product::query()->active()->whereKey($newIds)->get()->keyBy('id');
        $newIds = $newIds->filter(fn (int $id): bool => $products->has($id))->values();
        $contexts = $warehouseId > 0 && $newIds->isNotEmpty()
            ? $this->contexts->forProducts($actor, $warehouseId, $newIds->all(), $purchase)
            : [];

        foreach ($newIds as $productId) {
            $context = $contexts[$productId] ?? null;
            $suggestion = $context?->latestReceivedCost;
            $lines[(string) Str::uuid()] = [
                'product_id' => $productId,
                'ordered_quantity' => 1,
                'unit_cost' => $suggestion,
                'unit_cost_touched' => false,
                'unit_cost_suggested' => $suggestion !== null,
                'line_discount_total' => '0.00',
                'vat_rate' => '0.00',
                'notes' => null,
                ...$this->contextState($context),
            ];
        }

        return $lines;
    }

    /**
     * @param  array<string|int, array<string, mixed>>  $lines
     * @return array<string|int, array<string, mixed>>
     */
    public function enrich(array $lines, int $warehouseId, User $actor, ?Purchase $purchase = null): array
    {
        $productIds = collect($lines)->pluck('product_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values();
        $contexts = $warehouseId > 0 && $productIds->isNotEmpty()
            ? $this->contexts->forProducts($actor, $warehouseId, $productIds->all(), $purchase)
            : [];

        foreach ($lines as $key => $line) {
            $context = isset($line['product_id']) ? ($contexts[(int) $line['product_id']] ?? null) : null;
            $lines[$key] = [
                ...$line,
                'ordered_quantity' => $line['ordered_quantity'] ?? 1,
                'line_discount_total' => $line['line_discount_total'] ?? '0.00',
                'vat_rate' => $line['vat_rate'] ?? '0.00',
                'unit_cost_touched' => filled($line['unit_cost'] ?? null),
                'unit_cost_suggested' => false,
                ...$this->contextState($context),
            ];
        }

        return $lines;
    }

    /** @return array<string, mixed> */
    public function contextState(?PurchaseProductContext $context): array
    {
        if ($context === null) {
            return ['stock_context' => 'Select a Warehouse to load stock context.', 'latest_received_cost' => null];
        }

        $stock = "Available {$context->availableQuantity}; Reserved {$context->reservedQuantity}; Sellable {$context->sellableQuantity()}; Damaged {$context->damagedQuantity}; On hand {$context->totalOnHand()}; Other open PO {$context->outstandingOnOtherPurchases}.";
        $stock .= $context->latestReceivedCost === null
            ? ' No received purchase cost history.'
            : " Latest received cost AED {$context->latestReceivedCost}; weighted AED {$context->weightedReceivedCost}.";

        if ($context->inventoryAverageCost !== null) {
            $stock .= " Inventory average AED {$context->inventoryAverageCost}; value AED {$context->inventoryValue}.";
        }

        if ($context->productCostPrice !== null) {
            $stock .= " Product reference cost AED {$context->productCostPrice}.";
        }

        return ['stock_context' => $stock, 'latest_received_cost' => $context->latestReceivedCost];
    }
}
