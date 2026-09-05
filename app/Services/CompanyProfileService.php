<?php

namespace App\Services;

use App\Enums\CompanyProfilePermission;
use App\Models\CompanyProfile;
use App\Models\User;
use App\Services\Authorization\CompanyProfileAuthorization;
use Illuminate\Support\Facades\DB;

class CompanyProfileService
{
    public function __construct(private readonly CompanyProfileAuthorization $authorization, private readonly ActivityLogger $activity) {}

    public function profile(): ?CompanyProfile
    {
        return CompanyProfile::query()->find(1);
    }

    public function save(array $data, User $actor): CompanyProfile
    {
        $this->authorization->authorize($actor, CompanyProfilePermission::Manage);

        return DB::transaction(function () use ($data, $actor): CompanyProfile {
            $profile = CompanyProfile::query()->lockForUpdate()->find(1) ?? (new CompanyProfile)->forceFill(['id' => 1]);
            $created = ! $profile->exists;
            $profile->fill($data + ['updated_by_user_id' => $actor->id])->save();
            $this->activity->log($created ? 'company_profile.created' : 'company_profile.updated', $actor, $profile, ['profile_id' => 1, 'actor_id' => $actor->id]);

            return $profile->refresh();
        });
    }

    public function snapshot(): array
    {
        $profile = $this->profile();
        abort_unless($profile !== null, 422, 'Complete Company Profile before issuing an Invoice.');

        return $profile->only(['company_name_en', 'company_name_ar', 'logo_path', 'stamp_path', 'trn', 'trade_license_number', 'ded_registration_number', 'address_en', 'address_ar', 'phone', 'mobile', 'email', 'website', 'country', 'emirate', 'legal_statement_en', 'legal_statement_ar']);
    }
}
