<?php

namespace App\Actions\Responsibilities;

use App\DTOs\Responsibilities\RenameMarketplacePlatformData;
use App\Enums\ResponsibilityPermission;
use App\Models\MarketplacePlatform;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\ResponsibilityAuthorization;
use App\Services\Responsibilities\MarketplacePlatformNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class RenameMarketplacePlatform
{
    public function __construct(private readonly ResponsibilityAuthorization $authorization, private readonly MarketplacePlatformNormalizer $normalizer, private readonly ActivityLogger $activity) {}

    public function handle(MarketplacePlatform $platform, RenameMarketplacePlatformData $data, User $actor): MarketplacePlatform
    {
        $this->authorization->authorize($actor, ResponsibilityPermission::ManagePlatforms);
        $name = $this->normalizer->displayName($data->name);
        $normalized = $this->normalizer->normalizedName($data->name);
        Validator::make(compact('name', 'normalized'), ['name' => ['required', 'max:255'], 'normalized' => ['required', Rule::unique('marketplace_platforms', 'normalized_name')->ignore($platform->id)]])->validate();

        return DB::transaction(function () use ($platform, $name, $normalized, $actor): MarketplacePlatform {
            $platform = MarketplacePlatform::query()->lockForUpdate()->findOrFail($platform->id);
            $platform->forceFill(['name' => $name, 'normalized_name' => $normalized])->save();
            $this->activity->log('marketplace_platform.renamed', $actor, $platform, ['platform_id' => $platform->id, 'changed_fields' => ['name']]);

            return $platform;
        });
    }
}
