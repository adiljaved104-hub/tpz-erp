<x-filament-panels::page>
    <div class="mx-auto w-full max-w-2xl space-y-6">
        <x-filament::section heading="Login Information">
            <dl class="grid gap-4 sm:grid-cols-2">
                <div><dt class="text-sm text-gray-500">Employee</dt><dd class="font-medium">{{ $target->employee?->name ?? $target->name }}</dd></div>
                <div><dt class="text-sm text-gray-500">Current Login Email</dt><dd class="font-medium">{{ $target->email }}</dd></div>
                <div><dt class="text-sm text-gray-500">Company Email Status</dt><dd><x-filament::badge :color="$status === 'Approved' ? 'success' : 'warning'">{{ $status === 'Approved' ? 'Approved' : 'Company email update required' }}</x-filament::badge></dd></div>
                <div><dt class="text-sm text-gray-500">Approved Domain</dt><dd class="font-medium">{{ '@'.$domain }}</dd></div>
            </dl>
        </x-filament::section>

        <x-filament::section heading="Secure Email Change" :description="'Both the current mailbox and the new company mailbox must be verified. Codes expire after '.\App\Services\AuthenticationOtpService::EXPIRY_MINUTES.' minutes.'">
            @if($step === 'start')
                <p class="mb-4 text-sm text-gray-600 dark:text-gray-300">A verification code will be sent to the current login email. The address cannot be edited directly.</p>
                <x-filament::button wire:click="start" wire:loading.attr="disabled">Send Code to Current Email</x-filament::button>
            @elseif($step === 'current')
                <form wire:submit="verifyCurrent" class="max-w-md space-y-4">
                    <x-auth.otp-code-input name="currentCode" model="currentCode" label="Current Email Verification Code" :value="$currentCode" />
                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="verifyCurrent">
                        <span wire:loading.remove wire:target="verifyCurrent">Verify Current Email</span>
                        <span wire:loading wire:target="verifyCurrent">Verifying...</span>
                    </x-filament::button>
                </form>
                <x-auth.otp-resend-countdown
                    :seconds="$currentResendSeconds"
                    wire-action="resendCurrent"
                    :component-key="'current-'.$currentCooldownVersion"
                />
            @elseif($step === 'new')
                <form wire:submit="sendNewCode" class="max-w-md space-y-4">
                    <div><label class="mb-1 block text-sm font-medium">New Company Login Email</label><x-filament::input.wrapper :valid="!$errors->has('newEmail')"><x-filament::input type="email" wire:model="newEmail" autocomplete="email" placeholder="name@{{ $domain }}" /></x-filament::input.wrapper>@error('newEmail')<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror</div>
                    <x-filament::button type="submit" wire:loading.attr="disabled">Send Code to New Email</x-filament::button>
                </form>
            @elseif($step === 'verify-new')
                <form wire:submit="complete" class="max-w-md space-y-4">
                    <p class="text-sm text-gray-600 dark:text-gray-300">Enter the code sent to <span class="font-medium">{{ $newEmail }}</span>.</p>
                    <x-auth.otp-code-input name="newCode" model="newCode" label="New Email Verification Code" :value="$newCode" />
                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="complete">
                        <span wire:loading.remove wire:target="complete">Verify and Change Login Email</span>
                        <span wire:loading wire:target="complete">Verifying...</span>
                    </x-filament::button>
                </form>
                <x-auth.otp-resend-countdown
                    :seconds="$newResendSeconds"
                    wire-action="resendNew"
                    :component-key="'new-'.$newCooldownVersion"
                />
            @else
                <div class="space-y-3"><x-filament::badge color="success">Completed</x-filament::badge><p class="text-sm">The login email was changed successfully. Future authentication codes will be sent to the new address.</p></div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
