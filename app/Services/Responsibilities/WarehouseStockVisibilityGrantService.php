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
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class WarehouseStockVisibilityGrantService
{
    public function __construct(
        private readonly ResponsibilityAuthorization $authorization,
        private readonly ActivityLogger $activity,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): WarehouseStockVisibilityGrant
    {
        $data['employee_ids'] = $data['employee_ids'] ?? [$data['employee_id'] ?? null];
        $data['product_conditions'] = $data['product_conditions'] ?? [$data['product_condition'] ?? null];

        $grant = $this->createMany($data, $actor)->first();
        if (! $grant instanceof WarehouseStockVisibilityGrant) {
            throw ValidationException::withMessages(['scope' => 'No warehouse access grants were created.']);
        }

        return $grant;
    }

    /** @param array<string, mixed> $data @return Collection<int, WarehouseStockVisibilityGrant> */
    public function createMany(array $data, User $actor): Collection
    {
        $this->authorization->authorize($actor, ResponsibilityPermission::Assign);
        $data = Validator::make($data, [
            'employee_ids' => ['required', 'array', 'min:1', 'max:100'],
            'employee_ids.*' => ['required', 'integer', 'min:1'],
            'warehouse_id' => ['required', 'integer', 'min:1'],
            'product_conditions' => ['required', 'array', 'min:1'],
            'product_conditions.*' => ['required', 'in:all,new,renewed,used,open_box,refurbished'],
            'effective_at' => ['required', 'date', 'before_or_equal:now'],
            'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
            'view_stock' => ['sometimes', 'boolean'],
            'view_employee_stock_holders' => ['sometimes', 'boolean'],
            'adjust_warehouse_stock' => ['sometimes', 'boolean'],
            'consume_other_employees_stock' => ['sometimes', 'boolean'],
        ])->validate();

        $data['reason'] = trim($data['reason']);
        if ($data['reason'] === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }

        $employeeIds = array_values(array_unique(array_map('intval', $data['employee_ids'])));
        $conditions = array_values(array_unique($data['product_conditions']));
        if (in_array('all', $conditions, true)) {
            $conditions = ['new', 'renewed', 'used', 'open_box', 'refurbished'];
        }

        return DB::transaction(function () use ($actor, $data, $employeeIds, $conditions): Collection {
            $employees = Employee::query()->whereIn('id', $employeeIds)->where('status', true)
                ->whereNotNull('user_id')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($employees->count() !== count($employeeIds)) {
                throw ValidationException::withMessages(['employee_ids' => 'Select only active Employees linked to users.']);
            }

            $warehouse = Warehouse::query()->whereKey($data['warehouse_id'])->where('status', true)
                ->whereNotIn('location_type', [InventoryLocationType::MarketplaceFulfilment->value, InventoryLocationType::Transit->value])
                ->lockForUpdate()->first();
            if ($warehouse === null) {
                throw ValidationException::withMessages(['warehouse_id' => 'Select an active physical warehouse.']);
            }

            $grants = collect();
            foreach ($employees as $employee) {
                foreach ($conditions as $condition) {
                    $idempotencyKey = $this->rowIdempotencyKey(
                        $data['idempotency_key'], $employee->id, $warehouse->id, $condition,
                    );
                    $existing = WarehouseStockVisibilityGrant::query()->where('idempotency_key', $idempotencyKey)->first();
                    if ($existing !== null) {
                        $this->assertSameGrantOptions($existing, $data, $employee->id, $warehouse->id, $condition);
                        $grants->push($existing);

                        continue;
                    }

                    $fingerprint = $this->fingerprint($employee->id, $warehouse->id, $condition);
                    if (WarehouseStockVisibilityGrant::query()->active()->where('active_fingerprint', $fingerprint)->exists()) {
                        throw ValidationException::withMessages(['scope' => 'This exact shared warehouse visibility scope is already active for the selected employee.']);
                    }

                    $grant = WarehouseStockVisibilityGrant::query()->create([
                        'employee_id' => $employee->id,
                        'warehouse_id' => $warehouse->id,
                        'product_condition' => $condition,
                        'status' => 'active',
                        'active_fingerprint' => $fingerprint,
                        'effective_at' => $data['effective_at'],
                        'assigned_by_user_id' => $actor->id,
                        'idempotency_key' => $idempotencyKey,
                        'reason' => $data['reason'],
                        'view_stock' => (bool) ($data['view_stock'] ?? true),
                        'view_employee_stock_holders' => (bool) ($data['view_employee_stock_holders'] ?? true),
                        'adjust_warehouse_stock' => (bool) ($data['adjust_warehouse_stock'] ?? false),
                        'consume_other_employees_stock' => (bool) ($data['consume_other_employees_stock'] ?? false),
                    ]);

                    $this->activity->log('warehouse_stock_visibility.created', $actor, $grant, [
                        'employee_id' => $employee->id,
                        'warehouse_id' => $warehouse->id,
                        'product_condition' => $condition,
                        'capabilities' => [
                            'view_stock' => (bool) ($data['view_stock'] ?? true),
                            'view_employee_stock_holders' => (bool) ($data['view_employee_stock_holders'] ?? true),
                            'adjust_warehouse_stock' => (bool) ($data['adjust_warehouse_stock'] ?? false),
                            'consume_other_employees_stock' => (bool) ($data['consume_other_employees_stock'] ?? false),
                        ],
                    ]);

                    $grants->push($grant);
                }
            }

            $grants->each(fn (WarehouseStockVisibilityGrant $grant) => $grant->load(['employee', 'warehouse', 'assignedBy']));

            return $grants;
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

    /** @param array<string, mixed> $data */
    private function assertSameGrantOptions(WarehouseStockVisibilityGrant $grant, array $data, int $employeeId, int $warehouseId, string $condition): void
    {
        if ($grant->employee_id !== $employeeId
            || $grant->warehouse_id !== $warehouseId
            || $grant->product_condition !== $condition
            || $grant->effective_at?->timestamp !== Carbon::parse($data['effective_at'])->timestamp
            || $grant->reason !== $data['reason']
            || $grant->view_stock !== (bool) ($data['view_stock'] ?? true)
            || $grant->view_employee_stock_holders !== (bool) ($data['view_employee_stock_holders'] ?? true)
            || $grant->adjust_warehouse_stock !== (bool) ($data['adjust_warehouse_stock'] ?? false)
            || $grant->consume_other_employees_stock !== (bool) ($data['consume_other_employees_stock'] ?? false)) {
            throw ValidationException::withMessages(['idempotency_key' => 'This submission key was already used for a different warehouse access request.']);
        }
    }

    private function rowIdempotencyKey(string $requestKey, int $employeeId, int $warehouseId, string $condition): string
    {
        $hex = substr(hash('sha256', implode('|', [$requestKey, $employeeId, $warehouseId, $condition])), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return implode('-', [substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12)]);
    }
}
