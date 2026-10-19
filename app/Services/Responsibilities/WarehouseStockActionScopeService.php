<?php

namespace App\Services\Responsibilities;

use App\Models\ProductInventory;
use App\Models\User;
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
}
