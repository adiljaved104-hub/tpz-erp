<?php

namespace App\Services;

use App\Enums\AuthSecurityPermission;
use App\Models\CompanyProfile;
use App\Models\LoginSecuritySetting;
use App\Models\User;
use App\Services\Authorization\AuthSecurityAuthorization;
use App\Services\Branding\ApplicationBranding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class LoginBrandingService
{
    public function __construct(
        private readonly AuthSecurityAuthorization $authorization,
        private readonly ActivityLogger $activity,
        private readonly ApplicationBranding $applicationBranding,
    ) {}

    public function settings(): ?LoginSecuritySetting
    {
        return Schema::hasTable('login_security_settings') ? LoginSecuritySetting::query()->find(1) : null;
    }

    /** @return array{title: string, subtitle: string, logo_url: ?string, logo_source: string} */
    public function presentation(): array
    {
        $settings = $this->settings();
        $profile = Schema::hasTable('company_profiles') ? CompanyProfile::query()->find(1) : null;
        $dedicatedLogo = $this->existingPublicPath($settings?->login_logo_path);
        $companyLogo = $this->existingPublicPath($profile?->logo_path);
        $path = $dedicatedLogo ?: $companyLogo;

        return [
            'title' => $this->applicationBranding->loginTitle($settings?->login_title ?? config('auth_branding.title')),
            'subtitle' => filled($settings?->login_subtitle) ? $settings->login_subtitle : (string) config('auth_branding.subtitle', 'Internal Business Management System'),
            'logo_url' => filled($path) ? route('auth.branding.logo') : null,
            'logo_source' => filled($dedicatedLogo) ? 'login_settings' : (filled($companyLogo) ? 'company_profile' : 'text'),
        ];
    }

    public function logoPath(): ?string
    {
        $settings = $this->settings();
        $profile = Schema::hasTable('company_profiles') ? CompanyProfile::query()->find(1) : null;

        return $this->existingPublicPath($settings?->login_logo_path)
            ?: $this->existingPublicPath($profile?->logo_path);
    }

    private function existingPublicPath(?string $path): ?string
    {
        return filled($path) && Storage::disk('public')->exists($path) ? $path : null;
    }

    /** @param array{login_title: string, login_subtitle: string, login_logo_path?: ?string, allowed_login_email_domain?: string} $data */
    public function save(array $data, User $actor): LoginSecuritySetting
    {
        $this->authorization->authorize($actor, AuthSecurityPermission::Manage);

        return DB::transaction(function () use ($data, $actor): LoginSecuritySetting {
            $settings = LoginSecuritySetting::query()->lockForUpdate()->find(1) ?? (new LoginSecuritySetting)->forceFill(['id' => 1]);
            $settings->fill([
                'login_title' => trim($data['login_title']),
                'login_subtitle' => trim($data['login_subtitle']),
                'updated_by_user_id' => $actor->id,
            ]);
            if (array_key_exists('login_logo_path', $data)) {
                $settings->login_logo_path = $data['login_logo_path'];
            }
            if (array_key_exists('allowed_login_email_domain', $data)) {
                $settings->allowed_login_email_domain = app(CompanyEmailPolicyService::class)->normalizeDomain($data['allowed_login_email_domain']);
            }
            $settings->save();
            $this->activity->log('auth_security.branding_updated', $actor, $settings, ['actor_id' => $actor->id]);

            return $settings->refresh();
        });
    }
}
