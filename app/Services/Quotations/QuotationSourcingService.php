<?php

namespace App\Services\Quotations;

use App\Enums\InventoryPermission;
use App\Enums\OrderPermission;
use App\Enums\ProductStatus;
use App\Enums\QuotationItemSourceType;
use App\Enums\QuotationPermission;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\Quotation;
use App\Models\QuotationItemSourcingInstruction;
use App\Models\QuotationSourcingPosting;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\QuotationAuthorization;
use App\Services\Inventory\InventoryBalanceService;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\WeightedAverageCostCalculator;
use App\Services\Orders\OrderResponsibilityScopeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class QuotationSourcingService
{
    public function __construct(
        private readonly QuotationAuthorization $authorization,
        private readonly OrderResponsibilityScopeService $scope,
        private readonly InventoryBalanceService $balances,
        private readonly InventoryService $inventory,
        private readonly ActivityLogger $activity,
    ) {}

    public function canManage(User $actor, ?Quotation $quotation = null): bool
    {
        return $this->authorization->allows($actor, QuotationPermission::SourceInventory, $quotation)
            && $this->authorization->allows($actor, QuotationPermission::ViewSourceCost, $quotation);
    }

    public function authorizeManage(User $actor, ?Quotation $quotation = null): void
    {
        $this->authorization->authorize($actor, QuotationPermission::SourceInventory, $quotation);
        $this->authorization->authorize($actor, QuotationPermission::ViewSourceCost, $quotation);
    }

    public function canManageManualProducts(User $actor, ?Quotation $quotation = null): bool
    {
        return $this->canManage($actor, $quotation) && ! $this->scope->requiresScope($actor);
    }

    public function canPreviewMargin(User $actor): bool
    {
        return $this->authorization->allows($actor, QuotationPermission::ViewSourceCost)
            && app(OrderAuthorization::class)->allows($actor, OrderPermission::ViewProfit)
            && app(InventoryAuthorization::class)->allows($actor, InventoryPermission::ViewFinancials);
    }

    /** Internal estimate on the existing Order selling-price basis, never a customer projection. */
    public function marginPreview(array $line, int $warehouseId, User $actor): ?string
    {
        if (! $this->canPreviewMargin($actor)) {
            return null; // Deliberately before any financial SQL projection.
        }
        $quantity = (int) ($line['quantity'] ?? 0);
        $manual = ($line['source_type'] ?? QuotationItemSourceType::ExistingProduct->value) === QuotationItemSourceType::ManualSourced->value;
        $stock = $this->availability([$line], $warehouseId, $actor)[0] ?? null;
        if ($stock === null || $quantity < 1 || ! preg_match('/^\d{1,11}(?:\.\d{0,2})?$/', (string) ($line['unit_price_including_vat'] ?? ''))) {
            return null;
        }
        $balance = $manual ? null : ProductInventory::query()->where('product_id', $line['product_id'])->where('warehouse_id', $warehouseId)
            ->first(['id', 'available_quantity', 'damaged_quantity', 'average_cost']);
        $average = $balance?->average_cost;
        if ($stock['missing'] > 0) {
            $cost = (string) ($line['purchase_unit_cost'] ?? '');
            if ((! $manual && empty($line['source_inventory'])) || ! preg_match('/^\d{1,11}(?:\.\d{1,4})?$/', $cost) || bccomp($cost, '0', 4) <= 0
                || ($balance?->totalOnHand() > 0 && $average === null)) {
                return null;
            }
            $average = app(WeightedAverageCostCalculator::class)->weightedAverage($balance?->totalOnHand() ?? 0, $average, $stock['missing'], $cost);
        }
        if ($average === null || ! preg_match('/^\d{1,11}(?:\.\d{0,2})?$/', (string) ($line['discount_amount'] ?? '0'))) {
            return null;
        }
        $revenue = bcsub(bcmul((string) $quantity, (string) $line['unit_price_including_vat'], 4), (string) ($line['discount_amount'] ?? '0'), 4);

        return number_format((float) bcsub($revenue, bcmul((string) $quantity, (string) $average, 4), 4), 2, '.', '');
    }

    /** Read-only preview: costs are never selected. Allocation follows the submitted line order. */
    public function availability(array $lines, ?int $warehouseId, User $actor): array
    {
        if (! $warehouseId || ! $this->authorization->allows($actor, QuotationPermission::View)) {
            return [];
        }
        $products = $this->scope->applyProducts(Product::query()->products()->where('status', ProductStatus::Active), $actor, null, $warehouseId)
            ->whereKey(collect($lines)->pluck('product_id')->filter())->pluck('id');
        $remaining = ProductInventory::query()->where('warehouse_id', $warehouseId)->whereIn('product_id', $products)
            ->select(['product_id', 'available_quantity', 'reserved_quantity'])->get()
            ->mapWithKeys(fn ($i) => [$i->product_id => $i->sellableQuantity()])->all();
        $result = [];
        foreach ($lines as $key => $line) {
            if (($line['source_type'] ?? QuotationItemSourceType::ExistingProduct->value) === QuotationItemSourceType::ManualSourced->value) {
                $result[$key] = ['available' => 0, 'missing' => max(0, (int) ($line['quantity'] ?? 0))];

                continue;
            }
            $id = (int) ($line['product_id'] ?? 0);
            if (! $products->contains($id)) {
                continue;
            }
            $quantity = max(0, (int) ($line['quantity'] ?? 0));
            $available = max(0, $remaining[$id] ?? 0);
            $used = min($quantity, $available);
            $result[$key] = ['available' => $available, 'missing' => $quantity - $used];
            $remaining[$id] = $available - $used;
        }

        return $result;
    }

    /** Only an authorized caller may submit internal cost or sourcing changes. */
    public function validateInstructions(array $lines, int $warehouseId, User $actor, ?Quotation $quotation = null): array
    {
        $availability = $this->availability($lines, $warehouseId, $actor);
        $instructions = [];
        foreach ($lines as $key => $line) {
            $manual = ($line['source_type'] ?? QuotationItemSourceType::ExistingProduct->value) === QuotationItemSourceType::ManualSourced->value;
            if ($manual || ! empty($line['source_inventory']) || filled($line['purchase_unit_cost'] ?? null) || filled($line['source_note'] ?? null)) {
                $this->authorizeManage($actor, $quotation);
            }
            if ($manual && $this->scope->requiresScope($actor)) {
                throw ValidationException::withMessages([
                    "items.{$key}.source_type" => 'Manual sourced Products are unavailable under Responsibility-scoped access.',
                ]);
            }
            if (! $manual && empty($line['source_inventory'])) {
                continue;
            }
            if (! isset($availability[$key])) {
                throw ValidationException::withMessages(["items.{$key}.product_id" => 'This Product is outside your authorized Responsibility scope.']);
            }
            $missing = $availability[$key]['missing'];
            if (! $manual && $missing === 0 && blank($line['purchase_unit_cost'] ?? null)) {
                continue;
            }
            $validator = validator($line, [
                'purchase_unit_cost' => ['required', 'numeric', 'regex:/^\d{1,11}(?:\.\d{1,4})?$/', 'gt:0'],
                'source_note' => ['nullable', 'string', 'max:1000'],
            ]);
            if ($validator->fails()) {
                throw ValidationException::withMessages(collect($validator->errors()->messages())
                    ->mapWithKeys(fn ($messages, $field) => ["items.{$key}.{$field}" => $messages])->all());
            }
            $validated = $validator->validated();
            $instructions[$key] = [
                ...$validated,
                'planned_source_quantity_snapshot' => $missing,
                'configured_by_user_id' => $actor->id,
            ];
        }

        return $instructions;
    }

    /** Must run under the conversion transaction, after the quotation/warehouse locks. */
    public function lockedShortfalls(Quotation $quotation, int $warehouseId, User $actor): array
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Sourcing planning requires an active transaction.');
        }
        $remaining = [];
        $inventoryIds = [];
        $productIds = $quotation->items->map(fn ($item): ?int => $item->resolvedProductId())->filter()->unique()->sort();
        if ($productIds->count() !== $quotation->items->count()) {
            throw new \LogicException('Every quotation line must resolve to a Product before sourcing planning.');
        }
        foreach ($productIds as $productId) {
            $product = Product::query()->products()->whereKey($productId)->lockForUpdate()->first();
            if ($product?->status !== ProductStatus::Active || ! $this->scope->canAccessProduct($actor, $productId, null, $warehouseId)) {
                throw ValidationException::withMessages(['items' => 'A Product is inactive or outside your Responsibility scope.']);
            }
            $balance = $this->balances->lockOrCreate($productId, $warehouseId);
            $remaining[$productId] = $balance->sellableQuantity();
            $inventoryIds[$productId] = $balance->id;
        }
        $plan = [];
        foreach ($quotation->items as $line) {
            $productId = $line->resolvedProductId();
            $manual = $line->source_type === QuotationItemSourceType::ManualSourced;
            $used = $manual ? 0 : min($line->quantity, $remaining[$productId]);
            $remaining[$productId] -= $used;
            $missing = $manual ? $line->quantity : $line->quantity - $used;
            if ($missing === 0) {
                continue;
            }
            $this->authorizeManage($actor, $quotation);
            $instruction = QuotationItemSourcingInstruction::query()->where('quotation_item_id', $line->id)->lockForUpdate()->first();
            if ($instruction === null || bccomp((string) $instruction->purchase_unit_cost, '0', 4) <= 0) {
                throw ValidationException::withMessages(['items' => "{$line->sku} is short by {$missing}. An authorized sourcing instruction with Purchase Cost is required."]);
            }
            $plan[$line->id] = ['instruction' => $instruction, 'quantity' => $missing, 'inventory_id' => $inventoryIds[$productId]];
        }

        return $plan;
    }

    /** Called after Order/line identities exist and before the shared reservation operation. */
    public function post(Quotation $quotation, Order $order, array $plan, array $movementReferences, User $actor): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Sourcing posting requires an active transaction.');
        }
        $group = (string) Str::uuid();
        foreach ($quotation->items->values() as $index => $line) {
            $item = $order->items->values()[$index];
            if ($item->product_id !== $line->resolvedProductId() || $item->ordered_quantity !== $line->quantity) {
                throw new \LogicException('Quotation and Order line identities do not match.');
            }
            $item->forceFill(['quotation_item_id' => $line->id])->save();
            if (! isset($plan[$line->id])) {
                continue;
            }
            $this->authorizeManage($actor, $quotation);
            $source = $plan[$line->id];
            $instruction = $source['instruction'];
            $posting = QuotationSourcingPosting::query()->create([
                'sourcing_instruction_id' => $instruction->id,
                'quotation_item_id' => $line->id,
                'order_item_id' => $item->id,
                'product_inventory_id' => $source['inventory_id'],
                'quantity' => $source['quantity'],
                'purchase_unit_cost' => $instruction->purchase_unit_cost,
                'total_cost' => bcmul((string) $source['quantity'], (string) $instruction->purchase_unit_cost, 4),
                'source_note' => $instruction->source_note,
                'idempotency_key' => Uuid::uuid5(Uuid::NAMESPACE_URL, "quotation-source:{$quotation->order_conversion_idempotency_key}:{$line->id}")->toString(),
                'posted_by_user_id' => $actor->id,
                'posted_at' => now(),
            ]);
            $movement = $this->inventory->receiveQuotationSourcing($posting, $actor, $movementReferences[$line->id], $group);
            $this->activity->log('quotation.inventory_sourced', $actor, $posting, [
                'quotation_reference' => $quotation->reference,
                'order_reference' => $order->reference,
                'quotation_item_id' => $line->id,
                'order_item_id' => $item->id,
                'movement_reference' => $movement->reference,
                'quantity' => $posting->quantity,
            ]);
        }
    }

    /** Explicit projection for internal form fill; no cost query for unauthorized viewers. */
    public function formInstructions(Quotation $quotation, User $actor): array
    {
        if (! $this->authorization->allows($actor, QuotationPermission::ViewSourceCost, $quotation)) {
            return [];
        }

        return QuotationItemSourcingInstruction::query()->whereIn('quotation_item_id', $quotation->items->modelKeys())
            ->get(['quotation_item_id', 'purchase_unit_cost', 'source_note', 'planned_source_quantity_snapshot'])
            ->mapWithKeys(fn ($i) => [$i->quotation_item_id => [
                'source_inventory' => true, 'purchase_unit_cost' => (string) $i->purchase_unit_cost,
                'source_note' => $i->source_note,
            ]])->all();
    }

    /** Sourced quotations must pass through Order receipt/reservation before invoicing. */
    public function requiresOrderConversion(Quotation $quotation): bool
    {
        return $quotation->items()
            ->where('source_type', QuotationItemSourceType::ManualSourced->value)
            ->exists()
            || QuotationItemSourcingInstruction::query()
                ->whereIn('quotation_item_id', $quotation->items()->select('id'))
                ->value('quotation_item_id') !== null;
    }
}
