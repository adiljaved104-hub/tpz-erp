<?php

namespace App\Services\Responsibilities;

use App\Enums\InventoryLocationType;
use App\Enums\ResponsibilityPermission;
use App\Models\Employee;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStockVisibilityGrant;
use App\Services\ActivityLogger;
use App\Services\Authorization\ResponsibilityAuthorization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class WarehouseStockVisibilityGrantService
{
    public function __construct(
        private readonly ResponsibilityAuthorization $authorization,
        private readonly ActivityLogger $activity,
    ) {}

    /** @param array{employee_id:int,warehouse_id:int,product_condition:string,effective_at:string,reason:string,idempotency_key:string} $data */
    public function create(array $data, User $actor): WarehouseStockVisibilityGrant
    {
        $this->authorization->authorize($actor, ResponsibilityPermission::Assign);
        $data = Validator::make($data, [
            'employee_id' => ['required', 'integer', 'min:1'],
            'warehouse_id' => ['required', 'integer', 'min:1'],
            'product_condition' => ['required', 'in:new,renewed,used,open_box,refurbished'],
            'effective_at' => ['required', 'date', 'before_or_equal:now'],
            'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
        ])->validate();

        $data['reason'] = trim($data['reason']);
        if ($data['reason'] === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }

        return DB::transaction(function () use ($actor, $data): WarehouseStockVisibilityGrant {
            $employee = Employee::query()->whereKey($data['employee_id'])
                ->where('status', true)->whereNotNull('user_id')->lockForUpdate()->first();
            if ($employee === null) {
                throw ValidationException::withMessages(['employee_id' => 'Select an active Employee linked to a user.']);
            }

            $warehouse = Warehouse::query()->whereKey($data['warehouse_id'])->where('status', true)
                ->whereNotIn('location_type', [InventoryLocationType::MarketplaceFulfilment->value, InventoryLocationType::Transit->value])
                ->lockForUpdate()->first();
            if ($warehouse === null) {
                throw ValidationException::withMessages(['warehouse_id' => 'Select an active physical warehouse.']);
            }

            $existing = WarehouseStockVisibilityGrant::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                return $existing;
            }

            $fingerprint = $this->fingerprint($employee->id, $warehouse->id, $data['product_condition']);
            if (WarehouseStockVisibilityGrant::query()->active()->where('active_fingerprint', $fingerprint)->exists()) {
                throw ValidationException::withMessages(['scope' => 'This exact shared warehouse visibility scope is already active for the selected employee.']);
            }

            $grant = WarehouseStockVisibilityGrant::query()->create([
                'employee_id' => $employee->id,
                'warehouse_id' => $warehouse->id,
                'product_condition' => $data['product_condition'],
                'status' => 'active',
                'active_fingerprint' => $fingerprint,
                'effective_at' => $data['effective_at'],
                'assigned_by_user_id' => $actor->id,
                'idempotency_key' => $data['idempotency_key'],
                'reason' => trim($data['reason']),
            ]);

            $this->activity->log('warehouse_stock_visibility.created', $actor, $grant, [
                'employee_id' => $employee->id,
                'warehouse_id' => $warehouse->id,
                'product_condition' => $data['product_condition'],
            ]);

            return $grant->load(['employee', 'warehouse', 'assignedBy']);
        });
    }

    public function deactivate(WarehouseStockVisibilityGrant $grant, string $reason, User $actor): WarehouseStockVisibilityGrant
    {
        $this->authorization->authorize($actor, ResponsibilityPermission::Deactivate);

        if (trim($reason) === '' || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['reason' => 'A reason is required and may not exceed 2000 characters.']);
        }

        return DB::transaction(function () use ($actor, $grant, $reason): WarehouseStockVisibilityGrant {
            $locked = WarehouseStockVisibilityGrant::query()->lockForUpdate()->findOrFail($grant->id);
            if ($locked->status !== 'active' || $locked->ended_at !== null) {
                throw ValidationException::withMessages(['scope' => 'This visibility grant is no longer active.']);
            }

            $locked->forceFill([
                'status' => 'inactive',
                'active_fingerprint' => null,
                'ended_at' => now(),
                'ended_by_user_id' => $actor->id,
            ])->save();

            $this->activity->log('warehouse_stock_visibility.deactivated', $actor, $locked, [
                'employee_id' => $locked->employee_id,
                'warehouse_id' => $locked->warehouse_id,
                'product_condition' => $locked->product_condition,
            ], trim($reason));

            return $locked->refresh();
        });
    }

    private function fingerprint(int $employeeId, int $warehouseId, string $condition): string
    {
        return hash('sha256', implode('|', ['warehouse_stock_visibility', $employeeId, $warehouseId, $condition]));
    }
}
