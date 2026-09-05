<x-filament-panels::page>
    <div class="mx-auto w-full max-w-3xl space-y-6">
        <x-filament::section heading="Login Email" description="Your login email is the authoritative address for sign-in and authentication codes.">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="font-medium">{{ auth()->user()->email }}</p>
                    @php($companyEmailStatus = app(\App\Services\CompanyEmailPolicyService::class)->statusFor(auth()->user()))
                    <x-filament::badge :color="$companyEmailStatus === 'Approved' ? 'success' : 'warning'">{{ $companyEmailStatus === 'Approved' ? 'Approved' : 'Company email update required' }}</x-filament::badge>
                </div>
                @if(\Illuminate\Support\Facades\Schema::hasTable('login_email_change_requests'))
                    <x-filament::button tag="a" :href="\App\Filament\Pages\ChangeLoginEmail::getUrl()" outlined>Change Login Email</x-filament::button>
                @endif
            </div>
        </x-filament::section>
        <x-filament::section heading="Two-Factor Authentication" description="Add a one-time email code after your password when signing in.">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="font-medium">Email OTP 2FA</p>
                    <x-filament::badge :color="auth()->user()->email_two_factor_enabled_at ? 'success' : 'gray'">{{ auth()->user()->email_two_factor_enabled_at ? 'Enabled' : 'Disabled' }}</x-filament::badge>
                </div>
                @if(!auth()->user()->email_two_factor_enabled_at)
                    <x-filament::button wire:click="requestEnable">Enable Email 2FA</x-filament::button>
                @endif
            </div>

            @if($showEnableChallenge && !auth()->user()->email_two_factor_enabled_at)
                <form wire:submit="confirmEnable" class="mt-5 max-w-md space-y-3">
                    <x-auth.otp-code-input name="verificationCode" model="verificationCode" :value="$verificationCode" />
                    <x-filament::button type="submit">Verify and Enable</x-filament::button>
                </form>
                <x-auth.otp-resend-countdown
                    :seconds="$resendSeconds"
                    wire-action="resendEnable"
                    :component-key="'two-factor-'.$cooldownVersion"
                />
            @endif

            @if(auth()->user()->email_two_factor_enabled_at)
                <form wire:submit="disable" class="mt-5 max-w-md space-y-3">
                    <label class="block text-sm font-medium">Current Password</label>
                    <x-filament::input.wrapper :valid="!$errors->has('currentPassword')"><x-filament::input type="password" wire:model="currentPassword" autocomplete="current-password" /></x-filament::input.wrapper>
                    @error('currentPassword')<p class="text-sm text-danger-600">{{ $message }}</p>@enderror
                    <x-filament::button type="submit" color="danger" outlined>Disable Email 2FA</x-filament::button>
                </form>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
