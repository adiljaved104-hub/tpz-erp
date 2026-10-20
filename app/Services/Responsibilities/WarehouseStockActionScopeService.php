<?php

namespace App\Services\Responsibilities;

use App\Models\ProductInventory;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Explicit, condition-bounded capabilities attached to shared warehouse grants. */
class WarehouseStockActionScopeService
{
    public const ADJUST_STOCK = 'adjust_warehouse_stock';

    public const CONSUME_OTHER_EMPLOYEES_STOCK = 'consume_other_employees_stock';

    public function allows(User $user, ProductInventory $inventory, string $capability): bool
    {
        if (! in_array($capability, [self::ADJUST_STOCK, self::CONSUME_OTHER_EMPLOYEES_STOCK], true)
            || $user->employee?->status !== true
            || $user->employee->user_id !== $user->id) {
            return false;
        }

        $condition = $inventory->product?->condition?->value
            ?? DB::table('products')->where('id', $inventory->product_id)->value('condition');
        if (! is_string($condition) || $condition === '') {
            return false;
        }

        return DB::table('warehouse_stock_visibility_grants')
            ->where('employee_id', $user->employee->id)
            ->where('warehouse_id', $inventory->warehouse_id)
            ->where('product_condition', $condition)
            ->where('status', 'active')
            ->whereNull('ended_at')
            ->where($capability, true)
            ->exists();
    }

    public function allowsHolderSelection(User $user, ProductInventory $inventory): bool
    {
        if ($user->employee?->status !== true || $user->employee->user_id !== $user->id) {
            return false;
        }

        $condition = $inventory->product?->condition?->value
            ?? DB::table('products')->where('id', $inventory->product_id)->value('condition');

        return is_string($condition) && DB::table('warehouse_stock_visibility_grants')
            ->where('employee_id', $user->employee->id)
            ->where('warehouse_id', $inventory->warehouse_id)
            ->where('product_condition', $condition)
            ->where('status', 'active')->whereNull('ended_at')
            ->where('view_stock', true)->where('view_employee_stock_holders', true)->exists();
    }

    /**
     * Inventory rows visible to the Stock Adjustments workflow through an
     * explicit adjustment grant. Read-only warehouse grants are deliberately
     * excluded; the caller must separately verify inventory.adjust_stock.
     */
    public function adjustmentInventoryIds(User $user): Builder
    {
        $query = DB::table('product_inventories as adjustment_pi')->select('adjustment_pi.id');
        $employee = $user->employee;

        if ($employee?->status !== true || $employee->user_id !== $user->id) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereExists(function (Builder $grant) use ($employee): void {
            $grant->selectRaw('1')
                ->from('warehouse_stock_visibility_grants as adjustment_grant')
                ->join('products as adjustment_product', 'adjustment_product.id', '=', 'adjustment_pi.product_id')
                ->where('adjustment_grant.employee_id', $employee->id)
                ->where('adjustment_grant.status', 'active')
                ->whereNull('adjustment_grant.ended_at')
                ->where('adjustment_grant.view_stock', true)
                ->where('adjustment_grant.adjust_warehouse_stock', true)
                ->whereColumn('adjustment_grant.warehouse_id', 'adjustment_pi.warehouse_id')
                ->whereColumn('adjustment_grant.product_condition', 'adjustment_product.condition');
        });
    }
}
