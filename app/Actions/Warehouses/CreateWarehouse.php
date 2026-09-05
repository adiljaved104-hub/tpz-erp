<?php

namespace App\Actions\Warehouses;

use App\DTOs\Warehouses\CreateWarehouseData;
use App\Enums\InventoryLocationType;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ActivityLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateWarehouse
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function handle(CreateWarehouseData $data, User $actor): Warehouse
    {
        if (! $actor->can('create', Warehouse::class)) {
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
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_-]+$/', 'unique:warehouses,code'],
            'address' => ['nullable', 'string', 'max:2000'],
            'location_type' => ['required', 'string', Rule::enum(InventoryLocationType::class)],
            'marketplace_platform_id' => [Rule::requiredIf($data->locationType === InventoryLocationType::MarketplaceFulfilment), 'nullable', 'integer', 'exists:marketplace_platforms,id'],
            'fulfillment_tag' => ['nullable', 'string', 'max:50'],
        ])->validate();

        if ($data->locationType !== InventoryLocationType::MarketplaceFulfilment) {
            $validated['marketplace_platform_id'] = null;
            $validated['fulfillment_tag'] = null;
        }

        return DB::transaction(function () use ($validated, $actor): Warehouse {
            $warehouse = Warehouse::query()->create($validated + ['created_by_user_id' => $actor->getKey()]);
            $warehouse->forceFill(['status' => true, 'is_default' => false])->save();
            $this->activity->log('warehouse.created', $actor, $warehouse, [
                'code' => $warehouse->code,
                'location_type' => $warehouse->location_type->value,
                'active' => true,
            ]);

            return $warehouse;
        });
    }
}
