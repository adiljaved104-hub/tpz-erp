<?php

namespace App\Services\ServiceCases;

use App\Models\CustomerReturn;
use App\Models\MarketplacePlatform;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\WarrantyRepair;
use App\Services\Orders\OrderReadService;
use App\Services\Orders\OrderResponsibilityScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceCaseOrderContextService
{
    /** @return array<int, string> */
    public function __construct(
        private readonly OrderReadService $orderRead,
        private readonly OrderResponsibilityScopeService $responsibilities,
    ) {}

    public function searchOrders(string $search, ?User $user = null): array
    {
        return $this->ordersFor($user)
            ->where(function (Builder $query) use ($search): void {
                $query->where('reference', 'like', "%{$search}%")
                    ->orWhere('external_order_number', 'like', "%{$search}%");
            })
            ->latest('id')
            ->limit(50)
            ->get(['id', 'reference', 'external_order_number'])
            ->mapWithKeys(fn (Order $order): array => [$order->id => $this->orderLabel($order)])
            ->all();
    }

    public function orderLabel(Order $order): string
    {
        return $order->reference.(filled($order->external_order_number) ? " — {$order->external_order_number}" : '');
    }

    public function findOrder(int $orderId, ?User $user = null): ?Order
    {
        return $this->ordersFor($user)->whereKey($orderId)->first();
    }

    /** @return array<int, string> */
    public function orderProducts(int $orderId, ?User $user = null): array
    {
        return $this->ordersFor($user)->findOrFail($orderId)->items()
            ->with('product:id,sku,name')
            ->get()
            ->mapWithKeys(fn ($item): array => [$item->product_id => "{$item->product->sku} — {$item->product->name}"])
            ->all();
    }

    /** @return array{platform_id: int|null, warehouse_id: int, product_id: int|null, quantity: int, customer_return_id: int|null, warranty_repair_id: int|null} */
    public function context(int $orderId, ?User $user = null): array
    {
        $order = $this->ordersFor($user)->with('items:id,order_id,product_id,ordered_quantity')->findOrFail($orderId);
        $singleItem = $order->items->count() === 1 ? $order->items->first() : null;

        return [
            'platform_id' => $order->marketplace_platform_id,
            'warehouse_id' => $order->warehouse_id,
            'product_id' => $singleItem?->product_id,
            'quantity' => $singleItem?->ordered_quantity ?? 1,
            'customer_return_id' => CustomerReturn::query()->where('order_id', $order->id)->latest('id')->value('id'),
            'warranty_repair_id' => $singleItem ? $this->warrantyId($order->id, $singleItem->product_id, $user) : null,
        ];
    }

    public function productQuantity(int $orderId, int $productId, ?User $user = null): int
    {
        return (int) $this->ordersFor($user)->findOrFail($orderId)->items()->where('product_id', $productId)->value('ordered_quantity');
    }

    public function warrantyId(int $orderId, int $productId, ?User $user = null): ?int
    {
        $this->ordersFor($user)->findOrFail($orderId);

        return WarrantyRepair::query()->where('order_id', $orderId)->where('product_id', $productId)->latest('id')->value('id');
    }

    public function assertProductBelongsToOrder(?int $orderId, int $productId, ?User $user = null): void
    {
        if ($orderId !== null && ! $this->ordersFor($user)->whereKey($orderId)->whereHas('items', fn (Builder $query) => $query->where('product_id', $productId))->exists()) {
            throw ValidationException::withMessages(['product_id' => 'The selected Product does not belong to this Order.']);
        }
    }

    /** @return array<int, string> */
    public function productOptions(?int $platformId, ?int $warehouseId, ?User $user = null): array
    {
        $actor = $user ?? auth()->user();
        if (! $actor instanceof User) {
            return [];
        }

        $query = Product::query()->products()->where('status', 'active')->orderBy('name');
        if ($this->responsibilities->requiresScope($actor)) {
            if ($warehouseId === null) {
                return [];
            }

            $this->responsibilities->applyProducts($query, $actor, $platformId, $warehouseId);
        }

        return $query->get(['id', 'sku', 'name'])
            ->mapWithKeys(fn (Product $product): array => [$product->id => "{$product->sku} — {$product->name}"])
            ->all();
    }

    /** @return array<int, string> */
    public function searchProducts(string $search, ?int $platformId, ?int $warehouseId, ?User $user = null): array
    {
        if (mb_strlen(trim($search)) < 2) {
            return [];
        }

        $actor = $user ?? auth()->user();
        if (! $actor instanceof User) {
            return [];
        }

        $query = Product::query()->products()->where('status', 'active')
            ->where(fn (Builder $query) => $query
                ->where('sku', 'like', '%'.trim($search).'%')
                ->orWhere('name', 'like', '%'.trim($search).'%'));
        if ($this->responsibilities->requiresScope($actor)) {
            if ($warehouseId === null) {
                return [];
            }
            $this->responsibilities->applyProducts($query, $actor, $platformId, $warehouseId);
        }

        return $query->orderBy('sku')->limit(50)->get(['id', 'sku', 'name'])
            ->mapWithKeys(fn (Product $product): array => [$product->id => "{$product->sku} — {$product->name}"])
            ->all();
    }

    public function selectedProductLabel(int $productId, ?int $platformId, ?int $warehouseId, ?User $user = null): ?string
    {
        $actor = $user ?? auth()->user();
        if (! $actor instanceof User) {
            return null;
        }

        $query = Product::query()->products()->whereKey($productId)->where('status', 'active');
        if ($this->responsibilities->requiresScope($actor)) {
            if ($warehouseId === null) {
                return null;
            }
            $this->responsibilities->applyProducts($query, $actor, $platformId, $warehouseId);
        }
        $product = $query->first(['id', 'sku', 'name']);

        return $product ? "{$product->sku} — {$product->name}" : null;
    }

    /** @return array<int, string> */
    public function platformOptions(?User $user = null): array
    {
        $actor = $user ?? auth()->user();
        if (! $actor instanceof User) {
            return [];
        }

        $query = MarketplacePlatform::query()->active()->orderBy('name');
        if (! $this->responsibilities->requiresScope($actor)) {
            return $query->pluck('name', 'id')->all();
        }

        $employeeId = $actor->employee?->id;
        if ($employeeId === null) {
            return [];
        }

        $hasGlobalPlatformScope = DB::table('responsibility_assignments as platform_ra')
            ->where('platform_ra.employee_id', $employeeId)->where('platform_ra.status', 'active')->whereNull('platform_ra.ended_at')
            ->whereNotExists(fn ($scope) => $scope->selectRaw('1')->from('responsibility_assignment_platforms as platform_scope')->whereColumn('platform_scope.assignment_id', 'platform_ra.id'))
            ->exists();

        if ($hasGlobalPlatformScope) {
            return $query->pluck('name', 'id')->all();
        }

        return $query->whereIn('id', DB::table('responsibility_assignment_platforms as platform_scope')
            ->join('responsibility_assignments as platform_ra', 'platform_ra.id', '=', 'platform_scope.assignment_id')
            ->where('platform_ra.employee_id', $employeeId)->where('platform_ra.status', 'active')->whereNull('platform_ra.ended_at')
            ->select('platform_scope.marketplace_platform_id'))
            ->pluck('name', 'id')->all();
    }

    private function ordersFor(?User $user = null): Builder
    {
        $actor = $user ?? auth()->user();

        return $actor instanceof User
            ? $this->orderRead->orders($actor)
            : Order::query()->whereRaw('1 = 0');
    }
}
