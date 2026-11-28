<?php

namespace App\Services\Responsibilities;

use App\DTOs\Responsibilities\TransferResponsibilityAssignmentData;
use App\Enums\EmployeeRole;
use App\Enums\InventoryPermission;
use App\Enums\ResponsibilityAssignmentMode;
use App\Enums\ResponsibilityPermission;
use App\Models\InventoryAllocationAccount;
use App\Models\InventoryAllocationBalance;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\ResponsibilityAuthorization;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\ReferenceSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Explicit Owner-reviewed scope + stock handoff. Ordinary scope edits never move stock. */
class ResponsibilityStockTransitionService
{
    private function authorize(ResponsibilityAssignment $source, User $actor): void
    {
        abort_unless($actor->employee?->status === true && $actor->employee?->role === EmployeeRole::Owner, 403);
        app(ResponsibilityAuthorization::class)->authorize($actor, ResponsibilityPermission::Reassign, $source);
        app(InventoryAuthorization::class)->authorize($actor, InventoryPermission::ManageAllocations);
        $source->loadMissing(['brandScope', 'productScope', 'categoryScope', 'warehouseScope', 'conditionScope']);
        if ($source->assignment_mode !== ResponsibilityAssignmentMode::Scope || (! $source->brandScope && ! $source->productScope)) {
            throw ValidationException::withMessages(['transition' => 'This explicit handoff supports a brand or product responsibility. Quantity-only, condition-only and broad category responsibilities require a separate reviewed plan.']);
        }
    }

    private function balances(ResponsibilityAssignment $source)
    {
        return InventoryAllocationBalance::query()->where('allocated_quantity', '>', 0)
            ->whereHas('account', fn ($q) => $q->where('employee_id', $source->employee_id)->where('type', 'employee')->where('status', true))
            ->whereHas('inventory', function ($q) use ($source): void {
                $q->when($source->warehouseScope, fn ($q) => $q->where('warehouse_id', $source->warehouseScope->warehouse_id))
                    ->whereHas('product', fn ($q) => $q
                        ->when($source->productScope, fn ($q) => $q->whereKey($source->productScope->product_id))
                        ->when($source->brandScope, fn ($q) => $q->where('brand_id', $source->brandScope->product_brand_id))
                        ->when($source->categoryScope, fn ($q) => $q->where('category_id', $source->categoryScope->product_category_id))
                        ->when($source->conditionScope, fn ($q) => $q->where('condition', $source->conditionScope->product_condition)));
            })->with(['inventory.product:id,sku,name,status', 'inventory.warehouse:id,name,location_type'])->orderBy('product_inventory_id')->orderBy('account_id');
    }

    public function preview(ResponsibilityAssignment $source, User $actor): array
    {
        $this->authorize($source, $actor);

        return $this->balances($source)->get()->map(fn ($b) => [
            'inventory_id' => $b->product_inventory_id, 'source_account_id' => $b->account_id,
            'sku' => $b->inventory->product->sku, 'quantity' => $b->allocated_quantity,
            'blocked' => $b->reserved_quantity > 0 || $b->inventory->reserved_quantity > 0
                || $b->inventory->damaged_quantity > 0 || $b->inventory->qc_pending_quantity > 0
                || $b->inventory->warehouse->location_type->value !== 'company_warehouse'
                || $b->inventory->product->status->value !== 'active'
                || (int) $b->inventory->allocationBalances()->sum('allocated_quantity') !== $b->inventory->available_quantity
                || (int) $b->inventory->allocationBalances()->sum('reserved_quantity') !== $b->inventory->reserved_quantity,
        ])->all();
    }

    public function transfer(ResponsibilityAssignment $source, TransferResponsibilityAssignmentData $data, User $actor, array $expectedPlan): ResponsibilityAssignment
    {
        $this->authorize($source, $actor);
        Validator::make(['operation_key' => $data->idempotencyKey], ['operation_key' => ['required', 'uuid']])->validate();
        $existing = ResponsibilityAssignment::query()->where('idempotency_key', $data->idempotencyKey)->first();
        if ($existing) {
            abort_unless($existing->predecessor_assignment_id === $source->id && $existing->employee_id === $data->employeeId, 403);

            return $existing;
        }
        $reference = app(ReferenceSequenceService::class)->nextResponsibilityAssignmentReference();

        return DB::transaction(function () use ($source, $data, $actor, $expectedPlan, $reference): ResponsibilityAssignment {
            $locked = ResponsibilityAssignment::query()->lockForUpdate()->findOrFail($source->id);
            $existing = ResponsibilityAssignment::query()->where('idempotency_key', $data->idempotencyKey)->first();
            if ($existing) {
                abort_unless($existing->predecessor_assignment_id === $source->id && $existing->employee_id === $data->employeeId, 403);

                return $existing;
            }
            $this->authorize($locked, $actor);
            $rows = $this->balances($locked)->lockForUpdate()->get();
            ProductInventory::query()->whereIn('id', $rows->pluck('product_inventory_id'))->orderBy('id')->lockForUpdate()->get();
            $plan = $this->preview($locked->fresh(), $actor);
            if ($plan !== $expectedPlan || collect($plan)->contains('blocked', true)) {
                throw ValidationException::withMessages(['transition' => 'Stock changed or contains reserved, transfer-held, inactive, damaged, QC or marketplace stock. No responsibility or allocation was changed. Review a new plan.']);
            }
            $successor = app(ResponsibilityAssignmentService::class)->transfer($locked, $data, $actor, $reference);
            $ledger = app(InventoryAllocationService::class);
            $destination = $ledger->employeeAccount($data->employeeId);
            if (! $destination->status) {
                throw ValidationException::withMessages(['transition' => 'The destination allocation account is inactive. No changes were made.']);
            }
            foreach ($rows as $row) {
                $ledger->transferExact($row->inventory, InventoryAllocationAccount::findOrFail($row->account_id), $destination, $row->allocated_quantity, $actor, $successor, 'responsibility_stock_transfer', trim($data->reason));
            }
            app(ActivityLogger::class)->log('responsibility.stock_transitioned', $actor, $successor, ['predecessor_assignment_id' => $source->id, 'inventory_ids' => $rows->pluck('product_inventory_id')->all(), 'operation_key' => $data->idempotencyKey]);

            return $successor;
        }, 5);
    }
}
