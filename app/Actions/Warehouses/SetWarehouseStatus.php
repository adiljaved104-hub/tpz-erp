<?php

namespace App\Actions\Warehouses;

use App\DTOs\Warehouses\ChangeWarehouseStatusData;
use App\Enums\InventoryLocationType;
use App\Exceptions\DefaultWarehouseException;
use App\Exceptions\LastUsableWarehouseException;
use App\Exceptions\MainWarehouseImmutableException;
use App\Exceptions\WarehouseOperationalUseException;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ActivityLogger;
use App\Services\WarehouseUsageService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SetWarehouseStatus
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly WarehouseUsageService $usage,
    ) {}

    public function handle(Warehouse $warehouse, ChangeWarehouseStatusData $data, User $actor): Warehouse
    {
        if (! $actor->can('changeStatus', $warehouse)) {
            throw new AuthorizationException;
        }

        $validated = Validator::make(
            ['active' => $data->active, 'reason' => trim($data->reason)],
            ['active' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:1000']],
        )->validate();

        try {
            return DB::transaction(function () use ($warehouse, $validated, $actor): Warehouse {
                $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($warehouse->getKey());
                $previous = $warehouse->status;

                if ($previous === $validated['active']) {
                    return $warehouse;
                }

                if (! $validated['active']) {
                    if ($warehouse->code === 'MAIN') {
                        throw new MainWarehouseImmutableException('Main Warehouse cannot be deactivated.');
                    }

                    if ($warehouse->is_default) {
                        throw new DefaultWarehouseException('The default Warehouse cannot be deactivated.');
                    }

                    if ($warehouse->location_type === InventoryLocationType::CompanyWarehouse
                        && Warehouse::query()->where('location_type', InventoryLocationType::CompanyWarehouse)->where('status', true)->lockForUpdate()->count() <= 1) {
                        throw new LastUsableWarehouseException('The only usable Warehouse cannot be deactivated.');
                    }

                    $usage = $this->usage->check($warehouse);

                    if ($usage->used) {
                        throw new WarehouseOperationalUseException('Warehouse deactivation is blocked by: '.implode(', ', $usage->sources).'.');
                    }
                }

                $warehouse->forceFill(['status' => $validated['active']])->save();
                $this->activity->log('warehouse.status_changed', $actor, $warehouse, [
                    'from_active' => $previous,
                    'to_active' => $warehouse->status,
                    'reason' => $validated['reason'],
                ]);

                return $warehouse;
            });
        } catch (MainWarehouseImmutableException|DefaultWarehouseException|LastUsableWarehouseException|WarehouseOperationalUseException $exception) {
            $this->activity->log('warehouse.status_change_rejected', $actor, $warehouse, [
                'to_active' => $validated['active'],
                'reason' => $validated['reason'],
                'rejection' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }
}
