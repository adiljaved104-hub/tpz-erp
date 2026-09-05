<x-filament-panels::page>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-filament::section compact>
            <p class="text-xs text-gray-500">Backup Status</p>
            <p class="text-lg font-semibold">{{ $health['enabled'] ? 'Enabled' : 'Disabled' }}</p>
            <p class="text-xs text-gray-500">{{ $settingsSource }}</p>
        </x-filament::section>
        <x-filament::section compact>
            <p class="text-xs text-gray-500">Last Successful Backup</p>
            <p class="font-semibold">{{ $health['latest_at']?->setTimezone(config('app.timezone'))->format('d M Y, g:i A') ?? 'No backup yet' }}</p>
            <p class="text-xs text-gray-500">Checksums {{ $health['checksums_valid'] ? 'valid' : 'not verified' }}</p>
        </x-filament::section>
        <x-filament::section compact>
            <p class="text-xs text-gray-500">Backup Age</p>
            <p class="text-lg font-semibold">{{ $health['age_hours'] === null ? '—' : $health['age_hours'].' hours' }}</p>
            <p class="text-xs text-gray-500">Healthy within {{ config('backup.health.maximum_age_hours') }} hours</p>
        </x-filament::section>
        <x-filament::section compact>
            <p class="text-xs text-gray-500">Next Scheduled Backup</p>
            <p class="font-semibold">{{ $health['next_at']?->format('d M Y, g:i A') ?? 'Not scheduled' }}</p>
            <p class="text-xs text-gray-500">Configured disk: {{ ucfirst($health['backup_disk']) }}</p>
        </x-filament::section>
    </div>

    <form wire:submit="saveSettings" class="space-y-6">
        <x-filament::section heading="Backup Schedule" description="Automatic local backups remain disabled until explicitly enabled here.">
            <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                <label class="flex items-center gap-3"><input type="checkbox" wire:model.live="enabled" @disabled(!$canManage) class="rounded border-gray-300 text-primary-600"> <span class="font-medium">Backup Enabled</span></label>
                <div><label class="mb-1 block text-sm font-medium">Backup Time</label><x-filament::input.wrapper :valid="!$errors->has('backupTime')"><x-filament::input type="time" wire:model="backupTime" :disabled="!$canManage" /></x-filament::input.wrapper>@error('backupTime')<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror</div>
                <div><label class="mb-1 block text-sm font-medium">Backup Disk</label><select wire:model="backupDisk" @disabled(!$canManage) class="w-full rounded-lg border-gray-300 bg-white text-sm dark:border-white/10 dark:bg-white/5"><option value="local">Local protected storage</option></select></div>
                <label class="flex items-center gap-3"><input type="checkbox" wire:model="databaseEnabled" @disabled(!$canManage) class="rounded border-gray-300 text-primary-600"> <span>Database Backup Enabled</span></label>
                <label class="flex items-center gap-3"><input type="checkbox" wire:model="storageEnabled" @disabled(!$canManage) class="rounded border-gray-300 text-primary-600"> <span>Storage Backup Enabled</span></label>
                @error('databaseEnabled')<p class="text-sm text-danger-600 md:col-span-2">{{ $message }}</p>@enderror
            </div>
        </x-filament::section>

        <x-filament::section heading="Retention" description="Retention is applied only after a successful backup. The newest valid backup is always preserved.">
            <div class="grid gap-4 sm:grid-cols-3">
                @foreach([['dailyRetention','Daily copies',1,365],['weeklyRetention','Weekly copies',0,104],['monthlyRetention','Monthly copies',0,60]] as [$property,$label,$min,$max])
                    <div><label class="mb-1 block text-sm font-medium">{{ $label }}</label><x-filament::input.wrapper :valid="!$errors->has($property)"><x-filament::input type="number" :min="$min" :max="$max" wire:model="{{ $property }}" :disabled="!$canManage" /></x-filament::input.wrapper>@error($property)<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror</div>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section heading="Protection Readiness" description="No encryption keys, cloud credentials, or filesystem paths are displayed.">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10"><p class="font-medium">Offsite Status</p><p class="mt-1 text-sm text-gray-500">{{ $health['offsite_configured'] ? ($offsiteEnabled ? 'Enabled' : 'Configured, disabled') : 'Not configured' }}</p></div>
                <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10"><p class="font-medium">Encryption Status</p><p class="mt-1 text-sm text-gray-500">{{ $health['encryption_configured'] ? ($encryptionEnabled ? 'Enabled' : 'Configured, disabled') : 'Not configured' }}</p></div>
            </div>
        </x-filament::section>

        @if($canManage)<div class="flex justify-end"><x-filament::button type="submit" icon="heroicon-o-check" wire:loading.attr="disabled" wire:target="saveSettings"><span wire:loading.remove wire:target="saveSettings">Save Backup Settings</span><span wire:loading wire:target="saveSettings">Saving...</span></x-filament::button></div>@endif
    </form>

    <x-filament::section heading="Backup Operations" description="Restore remains an approved maintenance-runbook operation; this page never restores the active system.">
        <div class="flex flex-wrap gap-3">
            @if($canRun)<x-filament::button wire:click="runBackupNow" wire:confirm="Run a protected backup now? Retention runs only after it succeeds." wire:loading.attr="disabled" wire:target="runBackupNow" icon="heroicon-o-arrow-path"><span wire:loading.remove wire:target="runBackupNow">Run Backup Now</span><span wire:loading wire:target="runBackupNow">Running Backup...</span></x-filament::button>@endif
            @if($canVerify)<x-filament::button color="gray" wire:click="verifyLatestBackup" wire:confirm="Verify the latest successful backup manifest, checksums, and supported database integrity checks?" wire:loading.attr="disabled" wire:target="verifyLatestBackup" icon="heroicon-o-shield-check"><span wire:loading.remove wire:target="verifyLatestBackup">Verify Latest Backup</span><span wire:loading wire:target="verifyLatestBackup">Verifying...</span></x-filament::button>@endif
        </div>
        @if($canDownload)<p class="mt-3 text-xs text-gray-500">Direct browser download is intentionally unavailable: backups are protected multi-file bundles and remain accessible only through the approved server runbook.</p>@endif
    </x-filament::section>

    <x-filament::section heading="Backup History" description="The latest 25 manifest-backed attempts are shown. Protected server paths are never exposed.">
        <div class="mb-3 flex justify-end"><x-filament::button size="sm" color="gray" wire:click="refreshHistory" wire:loading.attr="disabled" wire:target="refreshHistory" icon="heroicon-o-arrow-path">Refresh History</x-filament::button></div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[900px] text-left text-sm">
                <thead class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-white/10"><tr><th class="px-3 py-2">Date / Time</th><th class="px-3 py-2">Status</th><th class="px-3 py-2">Database</th><th class="px-3 py-2">Storage</th><th class="px-3 py-2">Size</th><th class="px-3 py-2">Checksum</th><th class="px-3 py-2">Driver</th><th class="px-3 py-2">Age</th><th class="px-3 py-2"></th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse($history as $backup)
                        <tr>
                            <td class="px-3 py-3">{{ $backup['timestamp']->setTimezone(config('app.timezone'))->format('d M Y, g:i A') }}</td>
                            <td class="px-3 py-3"><x-filament::badge :color="$backup['status'] === 'successful' ? 'success' : 'danger'">{{ ucfirst($backup['status']) }}</x-filament::badge></td>
                            <td class="px-3 py-3">{{ $backup['database'] ? 'Included' : '—' }}</td>
                            <td class="px-3 py-3">{{ $backup['storage'] ? 'Included' : '—' }}</td>
                            <td class="px-3 py-3">{{ number_format($backup['bytes'] / 1048576, 2) }} MB</td>
                            <td class="px-3 py-3">{{ $backup['status'] === 'successful' ? 'Recorded' : 'Unavailable' }}</td>
                            <td class="px-3 py-3">{{ strtoupper($backup['driver']) }}</td>
                            <td class="px-3 py-3">{{ $backup['timestamp']->diffForHumans(short: true) }}</td>
                            <td class="px-3 py-3">@if($canVerify && $backup['status'] === 'successful')<x-filament::button size="sm" color="gray" wire:click="verifyBackup('{{ $backup['id'] }}')" wire:confirm="Verify this backup's manifest, checksums, and supported database integrity checks?" wire:loading.attr="disabled" wire:target="verifyBackup('{{ $backup['id'] }}')">Verify</x-filament::button>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-3 py-8 text-center text-gray-500">No backup history is available yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
