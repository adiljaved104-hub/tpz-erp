<?php

namespace App\Filament\Pages\Administration;

use App\Enums\AuthSecurityPermission;
use App\Models\User;
use App\Services\Authorization\AuthSecurityAuthorization;
use App\Services\CompanyEmailPolicyService;
use App\Services\LoginBrandingService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\WithFileUploads;

class LoginSecuritySettings extends Page
{
    use WithFileUploads;

    protected string $view = 'filament.pages.administration.login-security-settings';

    protected static ?string $slug = 'administration/login-security';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Login & Security';

    protected static ?string $title = 'Login & Security Settings';

    public string $loginTitle = 'Tech Point Zone ERP';

    public string $loginSubtitle = 'Internal Business Management System';

    public mixed $loginLogo = null;

    public ?string $currentLogoUrl = null;

    public string $allowedLoginEmailDomain = CompanyEmailPolicyService::DEFAULT_DOMAIN;

    public int $accountsNeedingMigration = 0;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(AuthSecurityAuthorization::class)->allows($user, AuthSecurityPermission::View);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(LoginBrandingService $branding, CompanyEmailPolicyService $emailPolicy): void
    {
        abort_unless(static::canAccess(), 403);
        $settings = $branding->settings();
        $presentation = $branding->presentation();
        $this->loginTitle = $settings?->login_title ?? $presentation['title'];
        $this->loginSubtitle = $settings?->login_subtitle ?? $presentation['subtitle'];
        $this->currentLogoUrl = $presentation['logo_url'];
        $this->allowedLoginEmailDomain = $emailPolicy->allowedDomain();
        $this->accountsNeedingMigration = $emailPolicy->activeAccountsNeedingMigrationCount();
    }

    public function save(LoginBrandingService $branding): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        app(AuthSecurityAuthorization::class)->authorize($actor, AuthSecurityPermission::Manage);
        $validated = $this->validate([
            'loginTitle' => ['required', 'string', 'max:120'],
            'loginSubtitle' => ['required', 'string', 'max:200'],
            'loginLogo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'allowedLoginEmailDomain' => ['required', 'string', 'max:190', 'regex:/^(?!-)[a-z0-9.-]+(?<!-)$/i'],
        ]);

        $oldPath = $branding->settings()?->login_logo_path;
        $newPath = null;
        if ($this->loginLogo) {
            $newPath = $this->loginLogo->storeAs('login-branding', Str::uuid().'.'.($this->loginLogo->guessExtension() ?: 'png'), 'public');
        }

        try {
            $settings = $branding->save([
                'login_title' => $validated['loginTitle'],
                'login_subtitle' => $validated['loginSubtitle'],
                ...($newPath ? ['login_logo_path' => $newPath] : []),
                ...(Schema::hasColumn('login_security_settings', 'allowed_login_email_domain')
                    ? ['allowed_login_email_domain' => $validated['allowedLoginEmailDomain']]
                    : []),
            ], $actor);
        } catch (\Throwable $exception) {
            if ($newPath) {
                Storage::disk('public')->delete($newPath);
            }
            throw $exception;
        }

        if ($newPath && $oldPath && $oldPath !== $settings->login_logo_path) {
            Storage::disk('public')->delete($oldPath);
        }
        $this->loginLogo = null;
        $this->currentLogoUrl = $branding->presentation()['logo_url'];
        $this->accountsNeedingMigration = app(CompanyEmailPolicyService::class)->activeAccountsNeedingMigrationCount();
        Notification::make()->success()->title('Login branding saved')->send();
    }

    public function getViewData(): array
    {
        return [
            'canManage' => auth()->user()?->can(AuthSecurityPermission::Manage->value) === true,
            'policyReady' => Schema::hasTable('login_email_change_requests'),
        ];
    }
}
