<?php

namespace App\Services\Inventory;

use App\Enums\InventoryAllocationMode;
use App\Enums\InventoryAllocationPolicy;
use App\Models\InventoryAllocationAccount;
use App\Models\InventoryAllocationRule;
use App\Models\InventoryAllocationSetting;
use App\Models\ProductInventory;
use Illuminate\Validation\ValidationException;

class InventoryAllocationPolicyService
{
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
        $policy = $this->policy();
        if ($policy === InventoryAllocationPolicy::AskAtGrn) {
            if ($selectedAccountId === null) {
                throw ValidationException::withMessages(['items' => 'Select an allocation account for each accepted GRN line.']);
            }

            $account = InventoryAllocationAccount::query()->where('status', true)->findOrFail($selectedAccountId);
            if ($this->mode() === InventoryAllocationMode::Strict && $account->is_system) {
                throw ValidationException::withMessages(['items' => 'Strict allocation requires an active Employee or Team allocation account.']);
            }

            return [$account, 'grn_selected'];
        }
        if ($policy === InventoryAllocationPolicy::Automatic) {
            $product = $inventory->product;
            $rule = InventoryAllocationRule::query()->where('status', true)
                ->where(fn ($query) => $query->whereNull('product_id')->orWhere('product_id', $product->id))
                ->where(fn ($query) => $query->whereNull('product_brand_id')->orWhere('product_brand_id', $product->brand_id))
                ->where(fn ($query) => $query->whereNull('product_category_id')->orWhere('product_category_id', $product->category_id))
                ->where(fn ($query) => $query->whereNull('warehouse_id')->orWhere('warehouse_id', $inventory->warehouse_id))
                ->with('targetAccount')->orderBy('priority')->orderBy('id')->first();
            if ($rule !== null && $rule->targetAccount?->status
                && ! ($this->mode() === InventoryAllocationMode::Strict && $rule->targetAccount->is_system)) {
                return [$rule->targetAccount, 'automatic_rule'];
            }
        }

        if ($this->mode() === InventoryAllocationMode::Strict) {
            $message = $policy === InventoryAllocationPolicy::Automatic
                ? 'No explicit allocation rule matched this GRN line. Correct the rules or use Ask at GRN.'
                : 'Strict allocation cannot finalize a GRN with No automatic allocation. Select Ask at GRN or configure an explicit rule.';
            throw ValidationException::withMessages(['items' => $message]);
        }

        $system = InventoryAllocationAccount::query()->firstOrCreate(['identity_key' => 'system'], [
            'type' => 'system', 'name' => 'System / Unallocated', 'is_system' => true, 'status' => true,
        ]);

        return [$system, $policy === InventoryAllocationPolicy::Automatic ? 'automatic_unmatched' : 'no_automatic'];
    }
}
