<?php

namespace App\Filament\Pages\Administration;

use App\Enums\CompanyProfilePermission;
use App\Models\User;
use App\Services\Authorization\CompanyProfileAuthorization;
use App\Services\CompanyProfileService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\WithFileUploads;

class CompanyProfile extends Page
{
    use WithFileUploads;

    protected string $view = 'filament.pages.administration.company-profile';

    protected static ?string $slug = 'administration/company-profile';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Company Profile';

    public array $data = [];

    public $logo;

    public $stamp;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(CompanyProfileAuthorization::class)->allows($user, CompanyProfilePermission::View);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(CompanyProfileService $service): void
    {
        abort_unless(static::canAccess(), 403);
        $this->data = $service->profile()?->toArray() ?? [];
    }

    public function save(CompanyProfileService $service): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        app(CompanyProfileAuthorization::class)->authorize($user, CompanyProfilePermission::Manage);
        $validated = $this->validate([
            'data.company_name_en' => ['required', 'string', 'max:255'], 'data.company_name_ar' => ['nullable', 'string', 'max:255'],
            'data.trn' => ['nullable', 'string', 'max:50'], 'data.trade_license_number' => ['nullable', 'string', 'max:100'], 'data.ded_registration_number' => ['nullable', 'string', 'max:100'],
            'data.address_en' => ['nullable', 'string', 'max:3000'], 'data.address_ar' => ['nullable', 'string', 'max:3000'],
            'data.phone' => ['nullable', 'string', 'max:50'], 'data.mobile' => ['nullable', 'string', 'max:50'], 'data.email' => ['nullable', 'email:rfc', 'max:255'],
            'data.website' => ['nullable', 'url:http,https', 'max:255'], 'data.country' => ['nullable', 'string', 'max:100'], 'data.emirate' => ['nullable', 'string', 'max:100'],
            'data.legal_statement_en' => ['nullable', 'string', 'max:3000'], 'data.legal_statement_ar' => ['nullable', 'string', 'max:3000'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'stamp' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);
        $data = $validated['data'];
        if ($this->logo) {
            $data['logo_path'] = $this->logo->storePubliclyAs('company-profile', Str::uuid().'.'.$this->logo->guessExtension(), 'public');
        }
        if ($this->stamp) {
            $data['stamp_path'] = $this->stamp->storePubliclyAs('company-profile', Str::uuid().'.'.$this->stamp->guessExtension(), 'public');
        }
        $profile = $service->save($data, $user);
        $this->data = $profile->toArray();
        $this->logo = null;
        $this->stamp = null;
        Notification::make()->success()->title('Company Profile saved')->send();
    }
}
