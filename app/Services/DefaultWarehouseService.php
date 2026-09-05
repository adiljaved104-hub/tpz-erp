<?php

namespace App\Services;

use App\Enums\InventoryLocationType;
use App\Exceptions\DefaultWarehouseException;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

class DefaultWarehouseService
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function operationalDefault(): Warehouse
    {
        $defaults = Warehouse::query()->where('location_type', InventoryLocationType::CompanyWarehouse)
            ->where('status', true)->where('is_default', true)->get();

        if ($defaults->count() !== 1) {
            throw new DefaultWarehouseException('Exactly one active default Warehouse is required.');
        }

        return $defaults->first();
    }

    public function switchTo(Warehouse $warehouse, string $reason, User $actor): Warehouse
    {
        return DB::transaction(function () use ($warehouse, $reason, $actor): Warehouse {
            $warehouses = Warehouse::query()->lockForUpdate()->get();
            $candidate = $warehouses->firstWhere('id', $warehouse->getKey());

            if ($candidate === null || ! $candidate->status) {
                throw new DefaultWarehouseException('An inactive or missing Warehouse cannot become default.');
            }

            if ($candidate->location_type !== InventoryLocationType::CompanyWarehouse) {
                throw new DefaultWarehouseException('Only an active Company Warehouse can become default.');
            }

            $defaults = $warehouses->where('location_type', InventoryLocationType::CompanyWarehouse)
                ->where('status', true)->where('is_default', true);

            if ($defaults->count() !== 1) {
                throw new DefaultWarehouseException('Exactly one active default Warehouse must exist before a default change.');
            }

            $previous = $defaults->first();

            if ($previous->is($candidate)) {
                return $candidate;
            }

            Warehouse::query()->where('is_default', true)->update(['is_default' => false]);
            $candidate->forceFill(['is_default' => true])->save();
            $this->activity->log('warehouse.default_changed', $actor, $candidate, [
                'previous_warehouse_id' => $previous->getKey(),
                'new_warehouse_id' => $candidate->getKey(),
                'reason' => $reason,
            ]);

            return $candidate;
        });
    }
}
