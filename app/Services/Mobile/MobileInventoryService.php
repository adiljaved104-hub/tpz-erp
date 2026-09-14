<?php

namespace App\Services\Mobile;

use App\Enums\DamagedStockPermission;
use App\Enums\InventoryPermission;
use App\Enums\ResponsibilityPermission;
use App\Models\User;
use App\Services\Authorization\DamagedStockAuthorization;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\ResponsibilityAuthorization;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use App\Services\Responsibilities\ResponsibilityReadService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MobileInventoryService
{
    public function query(User $user): Builder
    {
        abort_unless(app(InventoryAuthorization::class)->allows($user, InventoryPermission::View)
            || app(ResponsibilityAuthorization::class)->allows($user, ResponsibilityPermission::ViewOwn), 403);
        $query = DB::table('products as p')
            ->leftJoin('product_inventories as pi', 'pi.product_id', '=', 'p.id')
            ->leftJoin('product_brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'pi.warehouse_id');
        $scope = app(ResponsibilityProductScopeService::class);
        if ($scope->requiresScope($user)) {
            $query->where(function (Builder $visible) use ($scope, $user): void {
                $visible->whereIn('pi.id', $scope->inventoryIds($user))
                    ->orWhere(function (Builder $missing) use ($scope, $user): void {
                        $missing->whereNull('pi.id');
                        $scope->apply($missing, 'p.id', $user);
                    });
            });
        }

        return $query;
    }

    public function page(Request $request): array
    {
        $input = $request->validate(['q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:50', 'status' => 'nullable|in:out_of_stock,critical,low_stock,in_stock']);
        $user = $request->user();
        $query = $this->query($user)->select(['p.id as product_id', 'p.name', 'p.sku', 'b.name as brand', 'pi.id as inventory_id', 'pi.available_quantity', 'pi.reserved_quantity', 'pi.damaged_quantity', 'w.name as warehouse']);
        if (filled($input['q'] ?? null)) {
            $q = '%'.$input['q'].'%';
            $query->where(fn (Builder $match) => $match->where('p.name', 'like', $q)->orWhere('p.sku', 'like', $q)->orWhere('b.name', 'like', $q));
        }
        $status = app(StockStatus::class);
        $quantity = 'COALESCE(pi.available_quantity, 0) - COALESCE(pi.reserved_quantity, 0)';
        match ($input['status'] ?? '') {
            'out_of_stock' => $query->whereRaw("($quantity) <= 0"),
            'critical' => $query->whereRaw("($quantity) > 0 AND ($quantity) <= ?", [$status->critical()]),
            'low_stock' => $query->whereRaw("($quantity) > ? AND ($quantity) <= ?", [$status->critical(), $status->low()]),
            'in_stock' => $query->whereRaw("($quantity) > ?", [$status->low()]),
            default => null,
        };
        $page = $query->orderBy('p.name')->orderBy('pi.id')->paginate($input['per_page'] ?? 25);
        $own = app(ResponsibilityReadService::class)->myInventory($user, $page->getCollection()->pluck('product_id')->all())->keyBy('inventory_id');
        $damaged = app(DamagedStockAuthorization::class)->allows($user, DamagedStockPermission::View);
        $page->through(function ($row) use ($own, $damaged, $status): array {
            $allocation = $own->get($row->inventory_id);
            $available = (int) $row->available_quantity;
            $reserved = (int) $row->reserved_quantity;
            $sellable = max(0, $available - $reserved);
            $remaining = $allocation?->is_quantity_limited ? min($sellable, $allocation->remaining_allocation) : $sellable;

            return [
                'id' => $row->inventory_id ?? 'product-'.$row->product_id, 'product_id' => $row->product_id,
                'title' => $row->name, 'sku' => $row->sku, 'brand' => $row->brand,
                'subtitle' => collect([$row->sku, $row->brand, $row->warehouse])->filter()->implode(' · '),
                'meta' => "Available: $available · Reserved: $reserved · Sellable: $sellable · Remaining: $remaining",
                'available_quantity' => $available, 'reserved_quantity' => $reserved, 'sellable_quantity' => $sellable,
                'remaining_quantity' => max(0, $remaining),
                'assigned_quantity' => $allocation?->is_quantity_limited ? $allocation->assigned_quantity : null,
                'status' => $status->forQuantity($sellable),
                ...($damaged ? ['damaged_quantity' => (int) $row->damaged_quantity] : []),
            ];
        });

        return $page->toArray();
    }
}
