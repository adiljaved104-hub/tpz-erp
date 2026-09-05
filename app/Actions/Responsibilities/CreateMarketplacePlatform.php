<?php

namespace App\Actions\Responsibilities;

use App\DTOs\Responsibilities\CreateMarketplacePlatformData;
use App\Enums\InventoryLocationType;
use App\Enums\MarketplaceReturnHandlingMode;
use App\Enums\ResponsibilityPermission;
use App\Models\MarketplacePlatform;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\ResponsibilityAuthorization;
use App\Services\Responsibilities\MarketplacePlatformNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateMarketplacePlatform
{
    public function __construct(private readonly ResponsibilityAuthorization $authorization, private readonly MarketplacePlatformNormalizer $normalizer, private readonly ActivityLogger $activity) {}

    public function handle(CreateMarketplacePlatformData $data, User $actor): MarketplacePlatform
    {
        $this->authorization->authorize($actor, ResponsibilityPermission::ManagePlatforms);
        $name = $this->normalizer->displayName($data->name);
        $normalized = $this->normalizer->normalizedName($data->name);
        $code = $this->normalizer->code($data->code);
        $returnHandlingMode = $data->returnHandlingMode?->value;
        $defaultReturnReceivingWarehouseId = $data->defaultReturnReceivingWarehouseId;
        $customerReturnClaimsEnabled = $data->customerReturnClaimsEnabled;
        $claimProgramName = blank($data->claimProgramName) ? null : trim($data->claimProgramName);
        Validator::make(compact('name', 'normalized', 'code', 'returnHandlingMode', 'defaultReturnReceivingWarehouseId', 'customerReturnClaimsEnabled', 'claimProgramName'), [
            'name' => ['required', 'string', 'max:255'],
            'normalized' => ['required', 'max:255', 'unique:marketplace_platforms,normalized_name'],
            'code' => ['required', 'max:100', 'regex:/^[a-z0-9]+(?:_[a-z0-9]+)*$/', 'unique:marketplace_platforms,code'],
            'returnHandlingMode' => ['nullable', Rule::enum(MarketplaceReturnHandlingMode::class)],
            'defaultReturnReceivingWarehouseId' => [
                'nullable',
                'integer',
                Rule::exists('warehouses', 'id')->where(fn ($query) => $query
                    ->where('status', true)
                    ->where('location_type', '!=', InventoryLocationType::Transit->value)),
            ],
            'customerReturnClaimsEnabled' => ['boolean'], 'claimProgramName' => ['nullable', 'string', 'max:255'],
        ])->validate();

        return DB::transaction(function () use ($name, $normalized, $code, $returnHandlingMode, $defaultReturnReceivingWarehouseId, $customerReturnClaimsEnabled, $claimProgramName, $actor): MarketplacePlatform {
            $platform = new MarketplacePlatform;
            $platform->forceFill([
                'name' => $name,
                'normalized_name' => $normalized,
                'code' => $code,
                'status' => true,
                'created_by_user_id' => $actor->id,
                'return_handling_mode' => $returnHandlingMode,
                'default_return_receiving_warehouse_id' => $defaultReturnReceivingWarehouseId,
                'customer_return_claims_enabled' => $customerReturnClaimsEnabled,
                'claim_program_name' => $claimProgramName,
            ])->save();
            $this->activity->log('marketplace_platform.created', $actor, $platform, ['platform_id' => $platform->id, 'code' => $platform->code]);

            return $platform;
        });
    }
}
