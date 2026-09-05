<x-filament-panels::page>
    <x-filament::section heading="Recovery Target" description="Use recovery only when the employee cannot access the current mailbox.">
        <dl class="grid gap-3 sm:grid-cols-2">
            <div><dt class="text-sm text-gray-500">Employee</dt><dd class="font-medium">{{ $target->employee?->name }}</dd></div>
            <div><dt class="text-sm text-gray-500">Current Login Email</dt><dd class="font-medium">{{ $target->email }}</dd></div>
        </dl>
    </x-filament::section>

    <x-filament::section heading="Owner-Secured Recovery" description="The new mailbox must be verified before the login email changes.">
        @if ($step === 'reauth')
            <form wire:submit="start" class="grid max-w-xl gap-4">
                <div><label class="mb-1 block text-sm font-medium">Owner Password *</label><x-filament::input.wrapper :valid="!$errors->has('ownerPassword')"><x-filament::input type="password" wire:model="ownerPassword" autocomplete="current-password" /></x-filament::input.wrapper>@error('ownerPassword')<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror</div>
                <div><label class="mb-1 block text-sm font-medium">Recovery Reason *</label><textarea wire:model="reason" rows="4" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"></textarea>@error('reason')<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror</div>
                <x-filament::button type="submit" wire:loading.attr="disabled">Continue Secure Recovery</x-filament::button>
            </form>
        @elseif ($step === 'owner-2fa')
            <form wire:submit="verifyOwner" class="grid max-w-xl gap-4">
                <p class="text-sm">Enter the six-digit verification code sent to the Owner's login email.</p>
                <div><label class="mb-1 block text-sm font-medium">Owner Verification Code *</label><x-filament::input.wrapper :valid="!$errors->has('ownerTwoFactorCode')"><x-filament::input wire:model="ownerTwoFactorCode" inputmode="numeric" maxlength="6" /></x-filament::input.wrapper>@error('ownerTwoFactorCode')<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror</div>
                <x-filament::button type="submit" wire:loading.attr="disabled">Verify Owner</x-filament::button>
            </form>
        @elseif ($step === 'new-email')
            <form wire:submit="sendNewCode" class="grid max-w-xl gap-4">
                <div><label class="mb-1 block text-sm font-medium">New Company Login Email *</label><x-filament::input.wrapper :valid="!$errors->has('newEmail')"><x-filament::input type="email" wire:model="newEmail" placeholder="name@{{ $domain }}" /></x-filament::input.wrapper>@error('newEmail')<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror</div>
                <x-filament::button type="submit" wire:loading.attr="disabled">Send Verification Code</x-filament::button>
            </form>
        @elseif ($step === 'verify-new')
            <form wire:submit="complete" class="grid max-w-xl gap-4">
                <p class="text-sm">Enter the six-digit code sent to <strong>{{ $newEmail }}</strong>. The login email remains unchanged until verification succeeds.</p>
                <div><label class="mb-1 block text-sm font-medium">New Email Verification Code *</label><x-filament::input.wrapper :valid="!$errors->has('newCode')"><x-filament::input wire:model="newCode" inputmode="numeric" maxlength="6" /></x-filament::input.wrapper>@error('newCode')<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror</div>
                <x-filament::button type="submit" wire:loading.attr="disabled">Verify and Recover Login Email</x-filament::button>
                <x-filament::button type="button" color="gray" outlined wire:click="resendNewCode" wire:loading.attr="disabled">Resend Code</x-filament::button>
            </form>
        @else
            <x-filament::badge color="success">Recovery Completed</x-filament::badge>
        @endif
    </x-filament::section>
</x-filament-panels::page>
