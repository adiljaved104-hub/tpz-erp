@if(blank($this->userUndertakingMultiFactorAuthentication))
    <div class="mt-6 space-y-4">
        <div class="flex items-center gap-3 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
            <span class="h-px flex-1 bg-gray-200 dark:bg-white/10"></span>
            <span>or</span>
            <span class="h-px flex-1 bg-gray-200 dark:bg-white/10"></span>
        </div>
        <x-filament::button tag="a" :href="route('auth.otp.request')" color="gray" outlined class="w-full">
            Login with Email OTP
        </x-filament::button>
    </div>
@endif
