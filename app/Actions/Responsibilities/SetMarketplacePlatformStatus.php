<?php

namespace App\Actions\Responsibilities;

use App\DTOs\Responsibilities\ChangeMarketplacePlatformStatusData;
use App\Enums\ResponsibilityPermission;
use App\Models\MarketplacePlatform;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\ResponsibilityAuthorization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SetMarketplacePlatformStatus
{
    public function __construct(private readonly ResponsibilityAuthorization $authorization, private readonly ActivityLogger $activity) {}

    public function handle(MarketplacePlatform $platform, ChangeMarketplacePlatformStatusData $data, User $actor): MarketplacePlatform
    {
        $this->authorization->authorize($actor, ResponsibilityPermission::ManagePlatforms);
        if (trim($data->reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }

        return DB::transaction(function () use ($platform, $data, $actor): MarketplacePlatform {
            $platform = MarketplacePlatform::query()->lockForUpdate()->findOrFail($platform->id);
            $platform->forceFill(['status' => $data->active])->save();
            $this->activity->log('marketplace_platform.status_changed', $actor, $platform, ['platform_id' => $platform->id, 'status' => $data->active ? 'active' : 'inactive', 'reason' => $data->reason]);

            return $platform;
        });
    }
}
