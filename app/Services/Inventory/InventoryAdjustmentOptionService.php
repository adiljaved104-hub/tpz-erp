<?php

namespace App\Services\Inventory;

use App\Enums\EmployeeRole;
use App\Enums\InventoryPermission;
use App\Models\InventoryAllocationAccount;
use App\Models\ProductInventory;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Responsibilities\WarehouseStockActionScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class InventoryAdjustmentOptionService
{
    public function canAdjust(User $actor, ProductInventory $inventory): bool
    {
        $authorization = app(InventoryAuthorization::class);

        return $authorization->allows($actor, InventoryPermission::AdjustStock, $inventory)
            || ($authorization->allows($actor, InventoryPermission::AdjustStock)
                && app(WarehouseStockActionScopeService::class)->allows(
                    $actor, $inventory, WarehouseStockActionScopeService::ADJUST_STOCK,
                ));
    }

    /** @return array<int, string> */
    public function holderOptions(User $actor, ProductInventory $inventory, ?string $type): array
    {
        return $type === 'saleable_increase'
            ? $this->increaseHolderOptions($actor, $inventory)
            : $this->existingHolderOptions($actor, $inventory, $type);
    }

    public function requiresScopedHolderTarget(User $actor, ProductInventory $inventory): bool
    {
        return ! in_array($actor->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)
            && $this->canAdjust($actor, $inventory)
            && app(WarehouseStockActionScopeService::class)->allows(
                $actor, $inventory, WarehouseStockActionScopeService::ADJUST_STOCK,
            );
    }

    public function shouldSelectHolder(User $actor, ProductInventory $inventory, ?string $type): bool
    {
        if (blank($type)) {
            return false;
        }

        return $this->requiresScopedHolderTarget($actor, $inventory)
            || in_array($actor->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)
            || ($type === 'saleable_increase' && $this->increaseHolderOptions($actor, $inventory) !== []);
    }

    /** @return array<int, string> */
    private function increaseHolderOptions(User $actor, ProductInventory $inventory): array
    {
        if (! $this->canAdjust($actor, $inventory)) {
            return [];
        }

        if (app(WarehouseStockActionScopeService::class)->allows($actor, $inventory, WarehouseStockActionScopeService::ADJUST_STOCK)) {
            return $this->existingHolderOptions($actor, $inventory);
        }

        return InventoryAllocationAccount::query()->where('status', true)->orderBy('name')->get()
            ->filter(fn (InventoryAllocationAccount $account): bool => app(InventoryAllocationService::class)->canConsumeFromAccount($actor, $account, $inventory))
            ->pluck('name', 'id')->all();
    }

    /** @return array<int, string> */
    private function existingHolderOptions(User $actor, ProductInventory $inventory, ?string $type = null): array
    {
        if (! $this->canAdjust($actor, $inventory)) {
            return [];
        }

        $scope = app(WarehouseStockActionScopeService::class);
        $scopedAction = $scope->allows($actor, $inventory, WarehouseStockActionScopeService::ADJUST_STOCK);
        $isBroad = in_array($actor->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true);
        if (! $isBroad && (! $scopedAction || ! $scope->allowsHolderSelection($actor, $inventory))) {
            return [];
        }

        $accounts = InventoryAllocationAccount::query()->where('status', true);
        if ($isBroad) {
            $accounts->where(function (Builder $holders): void {
                $holders->where('is_system', true)
                    ->orWhere(function (Builder $employees): void {
                        $employees->where('is_system', false)->whereNotNull('employee_id')
                            ->whereHas('employee', fn (Builder $employee) => $employee->where('status', true)->whereNotNull('user_id'));
                    })
                    ->orWhere(function (Builder $teams): void {
                        $teams->where('is_system', false)->whereNotNull('team_id')
                            ->whereHas('team', fn (Builder $team) => $team->where('status', true));
                    });
            });
        } else {
            $accounts->where('is_system', false)->whereNotNull('employee_id')
                ->whereHas('employee', fn (Builder $employee) => $employee->where('status', true)->whereNotNull('user_id'));
        }
        $accounts->orderBy('name');
        if (in_array($type, ['saleable_decrease', 'mark_damaged'], true)) {
            $accounts->whereHas('balances', fn (Builder $balance) => $balance
                ->where('product_inventory_id', $inventory->id)->whereRaw('allocated_quantity > reserved_quantity'));
        } elseif ($type === 'restore_damaged') {
            $accounts->whereIn('id', DB::table('inventory_allocation_transition_lines')
                ->where('context_type', 'damaged')->where('source_product_inventory_id', $inventory->id)
                ->where('remaining_quantity', '>', 0)->select('account_id'));
        }

        return $accounts->pluck('name', 'id')->all();
    }
}
