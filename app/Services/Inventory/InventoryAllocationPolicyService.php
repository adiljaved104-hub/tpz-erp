<?php

namespace App\Services\Inventory;

use App\Enums\InventoryAllocationMode;
use App\Enums\InventoryAllocationPolicy;
use App\Enums\ResponsibilityAssignmentMode;
use App\Models\InventoryAllocationAccount;
use App\Models\InventoryAllocationSetting;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Services\Responsibilities\ResponsibilityScopeConflictEvaluator;
use Illuminate\Validation\ValidationException;

class InventoryAllocationPolicyService
{
    public function __construct(private readonly ResponsibilityScopeConflictEvaluator $scopeConflicts) {}

    public function settings(): InventoryAllocationSetting
    {
        return InventoryAllocationSetting::query()->firstOrCreate(['singleton_key' => 'inventory_allocation'], [
            'id' => 1,
            'enforcement_mode' => InventoryAllocationMode::MigrationShadow,
            'default_policy' => InventoryAllocationPolicy::NoAutomatic,
        ]);
    }

    public function mode(): InventoryAllocationMode
    {
        return $this->settings()->enforcement_mode;
    }

    public function policy(): InventoryAllocationPolicy
    {
        return $this->settings()->default_policy;
    }

    public function receiptAccount(ProductInventory $inventory, ?int $selectedAccountId): array
    {
        if ($selectedAccountId !== null) {
            $account = InventoryAllocationAccount::query()->where('status', true)->findOrFail($selectedAccountId);
            if ($this->mode() === InventoryAllocationMode::Strict && $account->is_system) {
                throw ValidationException::withMessages(['items' => 'Strict allocation requires an active Employee or Team allocation account.']);
            }

            return [$account, 'grn_selected'];
        }

        return $this->defaultResponsibilityAccount($inventory);
    }

    /** @return array{InventoryAllocationAccount, string} */
    private function defaultResponsibilityAccount(ProductInventory $inventory): array
    {
        $inventory->loadMissing(['product', 'warehouse']);
        $product = $inventory->product;

        $matches = ResponsibilityAssignment::query()->active()->where('assign_stock_by_default', true)
            ->where('assignment_mode', ResponsibilityAssignmentMode::Scope->value)
            ->whereHas('employee', fn ($query) => $query->where('status', true)->whereNotNull('user_id'))
            ->where(function ($query) use ($product): void {
                $query->whereHas('productScope', fn ($scope) => $scope->where('product_id', $product->id))
                    ->orWhereHas('brandScope', fn ($scope) => $scope->where('product_brand_id', $product->brand_id ?? 0))
                    ->orWhereHas('categoryScope', fn ($scope) => $scope->where('product_category_id', $product->category_id ?? 0));
            })
            ->where(fn ($query) => $query->whereDoesntHave('warehouseScope')
                ->orWhereHas('warehouseScope', fn ($scope) => $scope->where('warehouse_id', $inventory->warehouse_id)))
            ->where(fn ($query) => $query->whereDoesntHave('conditionScope')
                ->orWhereHas('conditionScope', fn ($scope) => $scope->where('product_condition', $product->condition?->value)))
            ->with(['employee:id,name,status,user_id', 'productScope', 'brandScope', 'categoryScope', 'conditionScope', 'warehouseScope', 'platformScope'])
            ->get()
            ->filter(fn (ResponsibilityAssignment $assignment): bool => $this->scopeConflicts->matchesDefaultStockForInventory($assignment, $inventory))
            ->values();
        $holders = $matches->unique('employee_id')->values();

        if ($holders->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'No default stock responsibility is configured for this product. Configure Responsibility before receiving this stock.']);
        }
        if ($holders->count() > 1) {
            throw ValidationException::withMessages(['items' => 'Multiple default stock responsibilities match this product. Resolve the Responsibility conflict before receiving stock.']);
        }

        $employee = $holders->first()->employee;
        $account = InventoryAllocationAccount::query()->firstOrCreate(['identity_key' => "employee:{$employee->id}"], [
            'employee_id' => $employee->id, 'type' => 'employee', 'name' => $employee->name,
            'is_system' => false, 'status' => true,
        ]);
        if (! $account->status) {
            throw ValidationException::withMessages(['items' => 'The default stock holder has an inactive allocation account. Resolve it before receiving stock.']);
        }

        return [$account, 'responsibility_default'];
    }
}
