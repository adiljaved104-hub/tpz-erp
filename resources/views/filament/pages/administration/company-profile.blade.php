<x-filament-panels::page>
    <form wire:submit="save" class="mx-auto w-full max-w-7xl space-y-6">
        <x-filament::section heading="Company Identity" description="Legal identity used across official ERP documents." icon="heroicon-o-building-office-2">
            <div class="grid min-w-0 gap-6 lg:grid-cols-2 lg:gap-8">
                <div class="min-w-0 space-y-5">
                    @foreach(['company_name_en' => 'Company Name — English', 'trn' => 'TRN', 'trade_license_number' => 'Trade License Number', 'country' => 'Country'] as $field => $label)
                        <div class="min-w-0">
                            <label for="company-profile-{{ $field }}" class="mb-1.5 block text-sm font-medium text-gray-950 dark:text-white">{{ $label }}</label>
                            <x-filament::input.wrapper :valid="! $errors->has('data.'.$field)"><x-filament::input id="company-profile-{{ $field }}" wire:model="data.{{ $field }}" /></x-filament::input.wrapper>
                            @error('data.'.$field)<p class="mt-1.5 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                </div>
                <div class="min-w-0 space-y-5">
                    <div class="min-w-0">
                        <label for="company-profile-company-name-ar" class="mb-1.5 block text-sm font-medium text-gray-950 dark:text-white">Company Name — Arabic</label>
                        <x-filament::input.wrapper :valid="! $errors->has('data.company_name_ar')">
                            <x-filament::input id="company-profile-company-name-ar" wire:model="data.company_name_ar" dir="rtl" class="w-full text-right" />
                        </x-filament::input.wrapper>
                        @error('data.company_name_ar')<p class="mt-1.5 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="min-w-0">
                        <label for="company-profile-ded-registration-number" class="mb-1.5 block text-sm font-medium text-gray-950 dark:text-white">DED Registration Number</label>
                        <x-filament::input.wrapper :valid="! $errors->has('data.ded_registration_number')">
                            <x-filament::input id="company-profile-ded-registration-number" wire:model="data.ded_registration_number" class="w-full" />
                        </x-filament::input.wrapper>
                        @error('data.ded_registration_number')<p class="mt-1.5 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="min-w-0">
                        <label for="company-profile-emirate" class="mb-1.5 block text-sm font-medium text-gray-950 dark:text-white">Emirate</label>
                        <x-filament::input.wrapper :valid="! $errors->has('data.emirate')">
                            <x-filament::input id="company-profile-emirate" wire:model="data.emirate" class="w-full" />
                        </x-filament::input.wrapper>
                        @error('data.emirate')<p class="mt-1.5 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                    </div>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section heading="Contact Information" description="Primary contact details shown on company documents." icon="heroicon-o-phone">
            <div class="grid min-w-0 gap-5 md:grid-cols-2">
                @foreach(['phone' => 'Phone', 'mobile' => 'Mobile', 'email' => 'Email', 'website' => 'Website'] as $field => $label)
                    <div class="min-w-0">
                        <label for="company-profile-{{ $field }}" class="mb-1.5 block text-sm font-medium text-gray-950 dark:text-white">{{ $label }}</label>
                        <x-filament::input.wrapper :valid="! $errors->has('data.'.$field)"><x-filament::input id="company-profile-{{ $field }}" wire:model="data.{{ $field }}" /></x-filament::input.wrapper>
                        @error('data.'.$field)<p class="mt-1.5 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section heading="Registered Address" description="Preserve line breaks exactly as they should appear on official documents." icon="heroicon-o-map-pin">
            <div class="grid min-w-0 gap-6 lg:grid-cols-2">
                <div class="min-w-0">
                    <label for="company-profile-address-en" class="mb-1.5 block text-sm font-medium text-gray-950 dark:text-white">Address — English</label>
                    <x-filament::input.wrapper :valid="! $errors->has('data.address_en')">
                        <textarea id="company-profile-address-en" wire:model="data.address_en" rows="5" dir="ltr" class="block min-h-36 w-full max-w-full min-w-0 resize-y border-0 bg-transparent px-3 py-3 text-left text-sm text-gray-950 outline-none focus:ring-0 dark:text-white" style="box-sizing: border-box; display: block; width: 100%; max-width: 100%; min-width: 0; min-height: 9rem; resize: vertical;"></textarea>
                    </x-filament::input.wrapper>
                    @error('data.address_en')<p class="mt-1.5 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                </div>
                <div class="min-w-0">
                    <label for="company-profile-address-ar" class="mb-1.5 block text-sm font-medium text-gray-950 dark:text-white">Address — Arabic</label>
                    <x-filament::input.wrapper :valid="! $errors->has('data.address_ar')">
                        <textarea id="company-profile-address-ar" wire:model="data.address_ar" rows="5" dir="rtl" class="block min-h-36 w-full max-w-full min-w-0 resize-y border-0 bg-transparent px-3 py-3 text-right text-sm text-gray-950 outline-none focus:ring-0 dark:text-white" style="box-sizing: border-box; display: block; width: 100%; max-width: 100%; min-width: 0; min-height: 9rem; resize: vertical;"></textarea>
                    </x-filament::input.wrapper>
                    @error('data.address_ar')<p class="mt-1.5 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                </div>
            </div>
        </x-filament::section>

        <x-filament::section heading="Legal Statement" description="Company legal wording printed on official documents." icon="heroicon-o-document-text">
            <div class="grid min-w-0 gap-6 xl:grid-cols-2">
                <div class="min-w-0">
                    <label for="company-profile-legal-en" class="mb-1.5 block text-sm font-medium text-gray-950 dark:text-white">Legal Statement — English</label>
                    <x-filament::input.wrapper :valid="! $errors->has('data.legal_statement_en')">
                        <textarea id="company-profile-legal-en" wire:model="data.legal_statement_en" rows="7" dir="ltr" class="block min-h-48 w-full max-w-full min-w-0 resize-y border-0 bg-transparent px-3 py-3 text-left text-sm text-gray-950 outline-none focus:ring-0 dark:text-white" style="box-sizing: border-box; display: block; width: 100%; max-width: 100%; min-width: 0; min-height: 12rem; resize: vertical;"></textarea>
                    </x-filament::input.wrapper>
                    @error('data.legal_statement_en')<p class="mt-1.5 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                </div>
                <div class="min-w-0">
                    <label for="company-profile-legal-ar" class="mb-1.5 block text-sm font-medium text-gray-950 dark:text-white">Legal Statement — Arabic</label>
                    <x-filament::input.wrapper :valid="! $errors->has('data.legal_statement_ar')">
                        <textarea id="company-profile-legal-ar" wire:model="data.legal_statement_ar" rows="7" dir="rtl" class="block min-h-48 w-full max-w-full min-w-0 resize-y border-0 bg-transparent px-3 py-3 text-right text-sm text-gray-950 outline-none focus:ring-0 dark:text-white" style="box-sizing: border-box; display: block; width: 100%; max-width: 100%; min-width: 0; min-height: 12rem; resize: vertical;"></textarea>
                    </x-filament::input.wrapper>
                    @error('data.legal_statement_ar')<p class="mt-1.5 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                </div>
            </div>
        </x-filament::section>

        <x-filament::section heading="Branding" description="Logo and stamp assets used by future official documents." icon="heroicon-o-photo">
            <div class="grid min-w-0 gap-6 lg:grid-cols-2">
                <div class="min-w-0 rounded-xl border border-gray-200 bg-gray-50/70 p-5 dark:border-white/10 dark:bg-white/5">
                    <div class="mb-4"><h3 class="text-sm font-semibold text-gray-950 dark:text-white">Company Logo</h3><p class="mt-1 text-xs text-gray-500 dark:text-gray-400">JPG, PNG or WebP · Maximum 2 MB</p></div>
                    <div class="mb-4 flex h-36 w-full items-center justify-center overflow-hidden rounded-lg border border-dashed border-gray-300 bg-white p-4 dark:border-white/15 dark:bg-gray-900/40">
                        @if(filled($data['logo_path'] ?? null))
                            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($data['logo_path']) }}" alt="Current company logo" class="max-h-full max-w-full object-contain">
                        @else
                            <div class="text-center text-sm text-gray-500 dark:text-gray-400"><x-filament::icon icon="heroicon-o-photo" class="mx-auto mb-2 h-7 w-7" />No logo uploaded</div>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <x-filament::button tag="label" for="company-profile-logo" icon="heroicon-o-arrow-up-tray" color="gray" outlined>
                            {{ filled($data['logo_path'] ?? null) ? 'Replace Logo' : 'Upload Logo' }}
                        </x-filament::button>
                        <span wire:loading wire:target="logo" class="text-xs text-gray-500 dark:text-gray-400">Uploading...</span>
                        @if($logo)<span class="max-w-full truncate text-xs text-gray-500 dark:text-gray-400">Selected: {{ $logo->getClientOriginalName() }}</span>@endif
                    </div>
                    <input id="company-profile-logo" type="file" wire:model="logo" accept="image/jpeg,image/png,image/webp" class="sr-only">
                    @error('logo')<p class="mt-1.5 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                </div>

                <div class="min-w-0 rounded-xl border border-gray-200 bg-gray-50/70 p-5 dark:border-white/10 dark:bg-white/5">
                    <div class="mb-4"><h3 class="text-sm font-semibold text-gray-950 dark:text-white">Company Stamp</h3><p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Transparent PNG recommended · Maximum 2 MB</p></div>
                    <div class="mb-4 flex h-36 w-full items-center justify-center overflow-hidden rounded-lg border border-dashed border-gray-300 bg-white p-4 dark:border-white/15 dark:bg-gray-900/40">
                        @if(filled($data['stamp_path'] ?? null))
                            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($data['stamp_path']) }}" alt="Current company stamp" class="max-h-full max-w-full object-contain">
                        @else
                            <div class="text-center text-sm text-gray-500 dark:text-gray-400"><x-filament::icon icon="heroicon-o-check-badge" class="mx-auto mb-2 h-7 w-7" />No stamp uploaded</div>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <x-filament::button tag="label" for="company-profile-stamp" icon="heroicon-o-arrow-up-tray" color="gray" outlined>
                            {{ filled($data['stamp_path'] ?? null) ? 'Replace Stamp' : 'Upload Stamp' }}
                        </x-filament::button>
                        <span wire:loading wire:target="stamp" class="text-xs text-gray-500 dark:text-gray-400">Uploading...</span>
                        @if($stamp)<span class="max-w-full truncate text-xs text-gray-500 dark:text-gray-400">Selected: {{ $stamp->getClientOriginalName() }}</span>@endif
                    </div>
                    <input id="company-profile-stamp" type="file" wire:model="stamp" accept="image/jpeg,image/png,image/webp" class="sr-only">
                    @error('stamp')<p class="mt-1.5 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                </div>
            </div>
        </x-filament::section>

        @can('company_profile.manage')
            <div class="sticky bottom-4 z-10 flex justify-end rounded-xl border border-gray-200 bg-white/95 p-4 shadow-lg backdrop-blur dark:border-white/10 dark:bg-gray-900/95">
                <x-filament::button type="submit" size="lg" icon="heroicon-o-check" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">Save Company Profile</span>
                    <span wire:loading wire:target="save">Saving...</span>
                </x-filament::button>
            </div>
        @endcan
    </form>
</x-filament-panels::page>
