<?php

namespace App\Actions\Responsibilities;

use App\DTOs\Responsibilities\UpdateMarketplaceReturnConfigurationData;
use App\Enums\InventoryLocationType;
use App\Enums\MarketplaceReturnHandlingMode;
use App\Enums\ResponsibilityPermission;
use App\Models\MarketplacePlatform;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\ResponsibilityAuthorization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UpdateMarketplaceReturnConfiguration
{
    public function __construct(
        private readonly ResponsibilityAuthorization $authorization,
        private readonly ActivityLogger $activity,
    ) {}

    public function handle(MarketplacePlatform $platform, UpdateMarketplaceReturnConfigurationData $data, User $actor): MarketplacePlatform
    {
        $this->authorization->authorize($actor, ResponsibilityPermission::ManagePlatforms);

        $validated = Validator::make([
            'return_handling_mode' => $data->returnHandlingMode?->value,
            'default_return_receiving_warehouse_id' => $data->defaultReturnReceivingWarehouseId,
            'customer_return_claims_enabled' => $data->customerReturnClaimsEnabled,
            'claim_program_name' => blank($data->claimProgramName) ? null : trim($data->claimProgramName),
        ], [
            'return_handling_mode' => ['nullable', Rule::enum(MarketplaceReturnHandlingMode::class)],
            'default_return_receiving_warehouse_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouses', 'id')->where(fn ($query) => $query
                    ->where('status', true)
                    ->where('location_type', '!=', InventoryLocationType::Transit->value)),
            ],
            'customer_return_claims_enabled' => ['boolean'],
            'claim_program_name' => ['nullable', 'string', 'max:255'],
        ], [
            'default_return_receiving_warehouse_id.exists' => 'Select an active, operational Inventory Location.',
        ])->validate();

        return DB::transaction(function () use ($platform, $validated, $actor): MarketplacePlatform {
            $platform = MarketplacePlatform::query()->lockForUpdate()->findOrFail($platform->id);
            $previous = [
                'return_handling_mode' => $platform->return_handling_mode?->value,
                'default_return_receiving_warehouse_id' => $platform->default_return_receiving_warehouse_id,
                'customer_return_claims_enabled' => $platform->customer_return_claims_enabled,
                'claim_program_name' => $platform->claim_program_name,
            ];
            $platform->forceFill($validated)->save();

            if ($platform->wasChanged(array_keys($validated))) {
                $this->activity->log('marketplace_platform.return_configuration_updated', $actor, $platform, [
                    'platform_id' => $platform->id,
                    'previous_return_mode' => $previous['return_handling_mode'],
                    'new_return_mode' => $platform->return_handling_mode?->value,
                    'previous_default_receiving_location_id' => $previous['default_return_receiving_warehouse_id'],
                    'new_default_receiving_location_id' => $platform->default_return_receiving_warehouse_id,
                    'claims_enabled' => $platform->customer_return_claims_enabled,
                    'claim_program_configured' => filled($platform->claim_program_name),
                    'reason_present' => false,
                ]);
            }

            return $platform->refresh();
        });
    }
}
