<x-filament-panels::page class="hr-page-form">
    <x-filament::section heading="Read-only monitoring" compact>
    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
        <x-filament::section compact>
            <div class="text-xs font-medium text-gray-500">Device</div>
            <div class="mt-1 font-semibold">{{ $host }}:{{ $port }}</div>
            <div class="mt-1"><x-filament::badge :color="$enabled ? 'success' : 'gray'">{{ $enabled ? 'Enabled' : 'Biometric Integration Disabled' }}</x-filament::badge></div>
            @unless($enabled)<p class="mt-2 text-xs text-gray-500">Hikvision synchronization is not enabled in this environment.</p>@endunless
            <div class="mt-1 text-xs text-gray-500">Scheduled sync: {{ ! $enabled ? 'Unavailable while integration is disabled' : ($scheduledSyncEnabled ? 'Armed' : 'Off') }}</div>
        </x-filament::section>
        <x-filament::section compact>
            <div class="text-xs font-medium text-gray-500">Last Attempt</div>
            <div class="mt-1 font-semibold">{{ $lastAttempt?->attempted_at?->timezone('Asia/Karachi')->format('d M Y, h:i A') ?? 'Never' }}</div>
            @if($lastAttempt)<div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-gray-500"><x-filament::badge :color="$lastAttempt->status === 'failed' ? 'danger' : ($lastAttempt->status === 'succeeded' ? 'success' : 'warning')">{{ $lastAttempt->status === 'failed' ? 'Synchronization Failed' : str($lastAttempt->status)->title() }}</x-filament::badge><span>{{ $lastAttempt->window_from->format('d M H:i') }}–{{ $lastAttempt->window_to->format('d M H:i') }}</span></div>@endif
        </x-filament::section>
        <x-filament::section compact>
            <div class="text-xs font-medium text-gray-500">Last Successful Sync</div>
            <div class="mt-1 font-semibold">{{ $lastSuccessful?->completed_at?->timezone('Asia/Karachi')->format('d M Y, h:i A') ?? 'Never' }}</div>
            @if($lastSuccessful)<div class="mt-1 text-xs text-gray-500">Imported {{ $lastSuccessful->imported_count }} · Duplicate {{ $lastSuccessful->duplicate_count }} · Skipped {{ $lastSuccessful->skipped_count }} · Unmapped {{ $lastSuccessful->unmapped_count }}</div>@endif
        </x-filament::section>
        <x-filament::section compact>
            <div class="text-xs font-medium text-gray-500">Pending work</div>
            <div class="mt-2 flex flex-wrap gap-2"><x-filament::badge :color="$unmappedEmployeeCount ? 'warning' : 'success'">{{ $unmappedEmployeeCount }} unmapped identifiers</x-filament::badge><x-filament::badge :color="$unprocessedEventCount ? 'warning' : 'success'">{{ $unprocessedEventCount }} unprocessed mapped events</x-filament::badge><x-filament::badge :color="$pendingRunCount ? 'warning' : 'gray'">{{ $pendingRunCount }} running syncs</x-filament::badge></div>
            <p class="mt-2 text-xs text-gray-500">This panel only reports state. It does not start or enable synchronization.</p>
        </x-filament::section>
    </div>
    @if($failedRuns->isNotEmpty())
        <div class="mt-4 overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10"><table class="w-full min-w-[650px] text-sm"><thead class="bg-gray-50 dark:bg-white/5"><tr>@foreach(['Recent failed / partial attempt', 'Mode', 'When', 'Imported', 'Duplicates', 'Skipped', 'Unmapped', 'Safe code'] as $heading)<th class="px-3 py-2 text-left font-semibold">{{ $heading }}</th>@endforeach</tr></thead><tbody class="divide-y divide-gray-100 dark:divide-white/5">@foreach($failedRuns as $run)<tr><td class="px-3 py-2"><x-filament::badge :color="$run->status === 'failed' ? 'danger' : 'warning'">{{ str($run->status)->title() }}</x-filament::badge></td><td class="px-3 py-2">{{ str($run->mode)->title() }}</td><td class="whitespace-nowrap px-3 py-2">{{ $run->attempted_at?->timezone('Asia/Karachi')->format('d M Y, h:i A') }}</td><td class="px-3 py-2">{{ $run->imported_count }}</td><td class="px-3 py-2">{{ $run->duplicate_count }}</td><td class="px-3 py-2">{{ $run->skipped_count }}</td><td class="px-3 py-2">{{ $run->unmapped_count }}</td><td class="px-3 py-2">{{ $run->safe_error_code ?: '—' }}</td></tr>@endforeach</tbody></table></div>
    @else
        <p class="mt-3 text-sm text-gray-500">No failed or partial synchronization attempts were recorded.</p>
    @endif
    </x-filament::section>

    <x-filament::section heading="Synchronization and recovery actions" compact>
        <p class="mb-4 text-sm text-gray-500">Actions below may contact the device or modify derived Attendance. Original biometric events remain immutable. No action runs automatically from this page.</p>
        <div class="grid gap-4 lg:grid-cols-2">
            <div class="min-w-0">
                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Device</div>
                <div class="flex flex-wrap gap-2">
                    <x-filament::button color="gray" wire:click="testConnection">Test Connection</x-filament::button>
                    <x-filament::button wire:click="syncNow" wire:confirm="Run a current-window biometric synchronization now?">Sync Now</x-filament::button>
                    <x-filament::button color="gray" x-on:click="$dispatch('open-modal', { id: 'hikvision-backfill' })">Backfill</x-filament::button>
                </div>
            </div>
            <div class="min-w-0">
                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Attendance Recovery</div>
                <div class="flex flex-wrap gap-2">{{ $this->reprocessPendingAttendanceAction }} {{ $this->rebuildMappedAttendanceAction }}</div>
                <div class="mt-2 space-y-1 text-xs text-gray-500">
                    <p><strong>Reprocess Pending:</strong> Processes mapped evidence that has not yet produced Attendance. Existing Attendance is not rebuilt.</p>
                    <p><strong>Rebuild Attendance:</strong> Recalculates selected Attendance using current rules while preserving original biometric events.</p>
                </div>
            </div>
        </div>
    </x-filament::section>

    @if($connectionResult)
        <x-filament::section compact>
            <x-filament::badge :color="$connectionResult['connected'] ? 'success' : ($connectionResult['status'] === 'Disabled' ? 'gray' : 'danger')">{{ $connectionResult['status'] }}</x-filament::badge>
            <div class="text-sm text-gray-500">{{ $connectionResult['message'] }}</div>
            @if($connectionResult['metadata'])<div class="mt-1 text-xs text-gray-500">Model {{ $connectionResult['metadata']['model'] ?? '—' }} · Firmware {{ $connectionResult['metadata']['firmware'] ?? '—' }}</div>@endif
        </x-filament::section>
    @endif

    <x-filament::section heading="Unmapped Employees" compact>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[760px] divide-y divide-gray-200 text-sm dark:divide-white/10">
                <thead><tr><th class="px-3 py-2 text-left">Hikvision Employee No.</th><th class="px-3 py-2 text-left">Name</th><th class="px-3 py-2 text-left">First Seen</th><th class="px-3 py-2 text-left">Last Seen</th><th class="px-3 py-2 text-right">Events</th><th class="px-3 py-2 text-right">Action</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse($unmapped as $row)
                        <tr><td class="px-3 py-2 font-medium">{{ $row->external_employee_identifier }}</td><td class="px-3 py-2">{{ $row->employee_name ?: '—' }}</td><td class="px-3 py-2">{{ \Carbon\CarbonImmutable::parse($row->first_seen)->timezone('Asia/Karachi')->format('d M Y, h:i A') }}</td><td class="px-3 py-2">{{ \Carbon\CarbonImmutable::parse($row->last_seen)->timezone('Asia/Karachi')->format('d M Y, h:i A') }}</td><td class="px-3 py-2 text-right">{{ $row->event_count }}</td><td class="px-3 py-2 text-right">{{ ($this->mapEmployeeAction)(['externalIdentifier' => $row->external_employee_identifier]) }}</td></tr>
                    @empty
                        <tr><td colspan="6" class="px-6 py-8 text-center text-gray-500">No unresolved biometric Employee identifiers.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::modal id="hikvision-backfill" width="lg">
        <x-slot name="heading">Confirm historical backfill</x-slot>
        <div class="space-y-4">
            <p class="text-sm text-gray-500">Query a bounded historical window. Maximum {{ $maximumBackfillDays }} days.</p>
            <label class="block space-y-1 text-sm"><span class="font-medium">From *</span><input type="datetime-local" wire:model="backfillFrom" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('backfillFrom')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
            <label class="block space-y-1 text-sm"><span class="font-medium">To *</span><input type="datetime-local" wire:model="backfillTo" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('backfillTo')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
        </div>
        <x-slot name="footerActions"><x-filament::button wire:click="backfill" wire:confirm="Import this historical Hikvision window?">Run Backfill</x-filament::button></x-slot>
    </x-filament::modal>

    <x-filament-actions::modals />
</x-filament-panels::page>
