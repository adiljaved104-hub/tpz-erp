<x-filament-panels::page>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-filament::section compact><p class="text-xs text-gray-500">Email Notifications</p><p class="font-semibold">{{ $enabled ? 'Enabled' : 'Disabled' }}</p></x-filament::section>
        <x-filament::section compact><p class="text-xs text-gray-500">SMTP Configured</p><p class="font-semibold">{{ $smtpConfigured ? 'Yes' : 'No' }}</p></x-filament::section>
        <x-filament::section compact><p class="text-xs text-gray-500">Last Successful Test</p><p class="font-semibold">{{ $settingsRecord?->last_successful_test_at?->format('d M Y, g:i A') ?? '—' }}</p></x-filament::section>
        <x-filament::section compact><p class="text-xs text-gray-500">Last Failed Test</p><p class="font-semibold">{{ $settingsRecord?->last_failed_test_at?->format('d M Y, g:i A') ?? '—' }}</p></x-filament::section>
    </div>

    <form wire:submit="saveSettings" class="space-y-6">
        <x-filament::section heading="Email Delivery">
            <div class="grid gap-4 md:grid-cols-2">
                <label class="flex items-center gap-3"><input type="checkbox" wire:model.live="enabled" @disabled(!$canManage) class="rounded border-gray-300 text-primary-600"> <span>Email Notifications Enabled</span></label>
                <div></div>
                <div><label class="mb-1 block text-sm font-medium">From Email</label><x-filament::input.wrapper :valid="!$errors->has('fromEmail')"><x-filament::input type="email" wire:model="fromEmail" :disabled="!$canManage" /></x-filament::input.wrapper>@error('fromEmail')<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror</div>
                <div><label class="mb-1 block text-sm font-medium">From Name</label><x-filament::input.wrapper :valid="!$errors->has('fromName')"><x-filament::input wire:model="fromName" :disabled="!$canManage" /></x-filament::input.wrapper>@error('fromName')<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror</div>
            </div>
        </x-filament::section>

        <x-filament::section heading="SMTP Server" description="Credentials are encrypted. A saved password is never returned to this form; leave it blank to preserve it.">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <div><label class="mb-1 block text-sm font-medium">SMTP Host</label><x-filament::input.wrapper :valid="!$errors->has('smtpHost')"><x-filament::input wire:model="smtpHost" :disabled="!$canManage" /></x-filament::input.wrapper>@error('smtpHost')<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror</div>
                <div><label class="mb-1 block text-sm font-medium">SMTP Port</label><x-filament::input.wrapper :valid="!$errors->has('smtpPort')"><x-filament::input type="number" min="1" max="65535" wire:model="smtpPort" :disabled="!$canManage" /></x-filament::input.wrapper>@error('smtpPort')<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror</div>
                <div><label class="mb-1 block text-sm font-medium">Encryption</label><select wire:model="encryption" @disabled(!$canManage) class="w-full rounded-lg border-gray-300 bg-white text-sm dark:border-white/10 dark:bg-white/5"><option value="tls">TLS</option><option value="ssl">SSL</option></select>@error('encryption')<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror</div>
                <div><label class="mb-1 block text-sm font-medium">SMTP Username</label><x-filament::input.wrapper :valid="!$errors->has('smtpUsername')"><x-filament::input wire:model="smtpUsername" autocomplete="username" :disabled="!$canManage" /></x-filament::input.wrapper>@error('smtpUsername')<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror</div>
                <div><label class="mb-1 block text-sm font-medium">SMTP Password</label><x-filament::input.wrapper :valid="!$errors->has('smtpPassword')"><x-filament::input type="password" wire:model="smtpPassword" autocomplete="new-password" placeholder="Leave blank to keep saved password" :disabled="!$canManage" /></x-filament::input.wrapper>@error('smtpPassword')<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror</div>
            </div>
            @if($canManage)<div class="mt-4"><x-filament::button type="submit" icon="heroicon-o-check">Save Settings</x-filament::button></div>@endif
        </x-filament::section>
    </form>

    @if($canTest)
        <x-filament::section heading="Test Connection" description="Sends one message immediately using the saved SMTP configuration. No credentials are displayed or logged.">
            <form wire:submit="sendTestEmail" class="flex max-w-2xl flex-col gap-3 sm:flex-row sm:items-end">
                <div class="min-w-0 flex-1"><label class="mb-1 block text-sm font-medium">Test Recipient</label><x-filament::input.wrapper :valid="!$errors->has('recipientEmail')"><x-filament::input type="email" wire:model="recipientEmail" /></x-filament::input.wrapper>@error('recipientEmail')<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror</div>
                <x-filament::button type="submit" icon="heroicon-o-paper-airplane">Send Test Email</x-filament::button>
            </form>
        </x-filament::section>
    @endif
</x-filament-panels::page>
