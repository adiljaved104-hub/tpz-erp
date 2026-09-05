<?php

namespace App\Actions\Warehouses;

use App\DTOs\Warehouses\UpdateWarehouseData;
use App\Enums\InventoryLocationType;
use App\Exceptions\MainWarehouseImmutableException;
use App\Exceptions\WarehouseCodeImmutableException;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ActivityLogger;
use App\Services\WarehouseUsageService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UpdateWarehouse
{
    public function __construct(
        private readonly WarehouseUsageService $usage,
        private readonly ActivityLogger $activity,
    ) {}

    public function handle(Warehouse $warehouse, UpdateWarehouseData $data, User $actor): Warehouse
    {
        if (! $actor->can('update', $warehouse)) {
            throw new AuthorizationException;
        }

        $validated = Validator::make([
            'name' => trim($data->name),
            'code' => strtoupper(trim($data->code)),
            'address' => filled($data->address) ? trim($data->address) : null,
            'location_type' => $data->locationType->value,
            'marketplace_platform_id' => $data->marketplacePlatformId,
            'fulfillment_tag' => filled($data->fulfillmentTag) ? trim($data->fulfillmentTag) : null,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('warehouses', 'code')->ignore($warehouse)],
            'address' => ['nullable', 'string', 'max:2000'],
            'location_type' => ['required', 'string', Rule::enum(InventoryLocationType::class)],
            'marketplace_platform_id' => [Rule::requiredIf($data->locationType === InventoryLocationType::MarketplaceFulfilment), 'nullable', 'integer', 'exists:marketplace_platforms,id'],
            'fulfillment_tag' => ['nullable', 'string', 'max:50'],
        ])->validate();

        if ($data->locationType !== InventoryLocationType::MarketplaceFulfilment) {
            $validated['marketplace_platform_id'] = null;
            $validated['fulfillment_tag'] = null;
        }

        try {
            return DB::transaction(function () use ($warehouse, $validated, $actor): Warehouse {
                $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($warehouse->getKey());

                if ($warehouse->code === 'MAIN' && ($validated['code'] !== 'MAIN' || $validated['name'] !== 'Main Warehouse')) {
                    throw new MainWarehouseImmutableException('Main Warehouse name and code are immutable.');
                }

                if ($warehouse->code === 'MAIN' && $validated['location_type'] !== InventoryLocationType::CompanyWarehouse->value) {
                    throw new MainWarehouseImmutableException('Main Warehouse must remain a Company Warehouse.');
                }

                if ($warehouse->code !== $validated['code'] && $this->usage->check($warehouse)->used) {
                    throw new WarehouseCodeImmutableException('A referenced Warehouse code cannot be changed.');
                }

                $warehouse->fill($validated);
                $changedFields = array_keys($warehouse->getDirty());
                $warehouse->save();

                if ($changedFields !== []) {
                    $this->activity->log('warehouse.updated', $actor, $warehouse, [
                        'changed_fields' => $changedFields,
                    ]);
                }

                return $warehouse;
            });
        } catch (MainWarehouseImmutableException $exception) {
            $this->activity->log('warehouse.main_identity_change_rejected', $actor, $warehouse, [
                'rejection' => $exception->getMessage(),
            ]);
            throw $exception;
        } catch (WarehouseCodeImmutableException $exception) {
            $this->activity->log('warehouse.code_change_rejected', $actor, $warehouse, [
                'rejection' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }
}
