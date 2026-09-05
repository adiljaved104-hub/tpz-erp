<x-filament-panels::page>
    <form wire:submit="save" class="mx-auto w-full max-w-5xl space-y-6">
        <x-filament::section heading="Login Branding" description="These details are shown only on ERP authentication screens. Company Profile branding remains unchanged.">
            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
                <div class="grid min-w-0 gap-5">
                    <div>
                        <label class="mb-1 block text-sm font-medium">Login Title</label>
                        <x-filament::input.wrapper :valid="!$errors->has('loginTitle')"><x-filament::input wire:model="loginTitle" :disabled="!$canManage" /></x-filament::input.wrapper>
                        @error('loginTitle')<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Login Subtitle</label>
                        <x-filament::input.wrapper :valid="!$errors->has('loginSubtitle')"><x-filament::input wire:model="loginSubtitle" :disabled="!$canManage" /></x-filament::input.wrapper>
                        @error('loginSubtitle')<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror
                    </div>
                    @if($canManage)
                        <div>
                            <label class="mb-1 block text-sm font-medium">Login Logo</label>
                            <input type="file" wire:model="loginLogo" accept="image/jpeg,image/png,image/webp" class="block w-full rounded-lg border border-gray-300 p-2 text-sm dark:border-white/10" />
                            <p class="mt-1 text-xs text-gray-500">JPG, PNG or WebP · Maximum 2 MB. A safe generated filename is used.</p>
                            @error('loginLogo')<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror
                        </div>
                    @endif
                </div>
                <div class="flex min-h-48 items-center justify-center rounded-xl border border-dashed border-gray-300 bg-gray-50 p-6 dark:border-white/10 dark:bg-white/5">
                    @if($currentLogoUrl)
                        <img src="{{ $currentLogoUrl }}" alt="Current login logo" class="max-h-32 max-w-full object-contain" />
                    @else
                        <p class="text-center text-sm text-gray-500">No logo uploaded.<br>Text branding will be shown.</p>
                    @endif
                </div>
            </div>
        </x-filament::section>

        <x-filament::section heading="Company Login Email Policy" description="Only exact email addresses at this domain may authenticate. Subdomains and lookalike domains are not accepted.">
            @unless($policyReady)<p class="mb-4 rounded-lg bg-warning-50 p-3 text-sm text-warning-700 dark:bg-warning-500/10 dark:text-warning-300">The prepared company-email security migration must be approved and run before this policy can be changed.</p>@endunless
            <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_18rem] lg:items-start">
                <div>
                    <label class="mb-1 block text-sm font-medium">Allowed Login Email Domain</label>
                    <x-filament::input.wrapper :valid="!$errors->has('allowedLoginEmailDomain')">
                        <div class="flex items-center"><span class="pl-3 text-sm text-gray-500">@</span><x-filament::input wire:model="allowedLoginEmailDomain" :disabled="!$canManage || !$policyReady" /></div>
                    </x-filament::input.wrapper>
                    @error('allowedLoginEmailDomain')<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror
                    <p class="mt-2 text-xs text-gray-500">Applies to password login, email OTP, two-factor verification, and password reset.</p>
                </div>
                <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <p class="text-2xl font-semibold">{{ $accountsNeedingMigration }}</p>
                    <p class="text-sm text-gray-500">active account{{ $accountsNeedingMigration === 1 ? '' : 's' }} requiring a company login email</p>
                </div>
            </div>
        </x-filament::section>

        @if($canManage)
            <div class="flex justify-end"><x-filament::button type="submit" wire:loading.attr="disabled">Save Login & Security Settings</x-filament::button></div>
        @endif
    </form>
</x-filament-panels::page>
