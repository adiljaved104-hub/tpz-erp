<?php

namespace App\Actions\Responsibilities;

use App\DTOs\Responsibilities\ChangeMarketplacePlatformCodeData;
use App\Enums\ResponsibilityPermission;
use App\Exceptions\MarketplacePlatformCodeChangeException;
use App\Models\MarketplacePlatform;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\ResponsibilityAuthorization;
use App\Services\Responsibilities\MarketplacePlatformNormalizer;
use App\Services\Responsibilities\MarketplacePlatformUsageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ChangeMarketplacePlatformCode
{
    public function __construct(
        private readonly ResponsibilityAuthorization $authorization,
        private readonly MarketplacePlatformNormalizer $normalizer,
        private readonly MarketplacePlatformUsageService $usage,
        private readonly ActivityLogger $activity,
    ) {}

    public function handle(MarketplacePlatform $platform, ChangeMarketplacePlatformCodeData $data, User $actor): MarketplacePlatform
    {
        $this->authorization->authorize($actor, ResponsibilityPermission::ManagePlatforms);
        $newCode = $this->normalizer->code($data->newCode);
        $reason = trim($data->reason);

        Validator::make(['new_code' => $newCode, 'reason' => $reason], [
            'new_code' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-z0-9]+(?:_[a-z0-9]+)*$/',
                Rule::notIn([$platform->code]),
                Rule::unique('marketplace_platforms', 'code')->ignore($platform->id),
            ],
            'reason' => ['required', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($platform, $newCode, $reason, $actor): MarketplacePlatform {
            $platform = MarketplacePlatform::query()->lockForUpdate()->findOrFail($platform->id);

            if ($this->usage->check($platform)->used) {
                throw new MarketplacePlatformCodeChangeException('Platform code cannot be changed because this Platform is already in use.');
            }

            $oldCode = $platform->code;
            $platform->forceFill(['code' => $newCode])->saveQuietly();
            $this->activity->log('marketplace_platform.code_changed', $actor, $platform, [
                'platform_id' => $platform->id,
                'old_code' => $oldCode,
                'new_code' => $newCode,
                'actor_user_id' => $actor->id,
                'reason' => $reason,
            ]);

            return $platform;
        });
    }
}
