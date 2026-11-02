<x-filament-panels::page>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
        @foreach([
            ['Total Active Repairs', $summary['active'], 'heroicon-o-wrench-screwdriver', 'blue'],
            ['With Technician', $summary['technician'], 'heroicon-o-user-group', 'cyan'],
            ['Waiting for Parts', $summary['parts'], 'heroicon-o-clock', 'amber'],
            ['Ready for QC', $summary['qc'], 'heroicon-o-check-circle', 'emerald'],
            ['Pending Classification', $summary['classification'], 'heroicon-o-tag', 'violet'],
            ['Overdue Repairs', $summary['overdue'], 'heroicon-o-exclamation-circle', 'red'],
        ] as [$label, $value, $icon, $accent])
            @include('filament.widgets.dashboard.metric-card', ['metricTitle' => $label, 'metricValue' => $value, 'metricIcon' => $icon, 'metricAccent' => $accent, 'metricShowOpen' => false])
        @endforeach
    </div>

    <x-filament::section>
        <div class="flex flex-wrap gap-2" role="tablist" aria-label="Repair status">
            @foreach($tabs as $key => $label)
                <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="setTab('{{ $key }}')"
                    @class(['rounded-lg px-3 py-2 text-sm font-semibold', 'bg-primary-600 text-white' => $tab === $key, 'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-200' => $tab !== $key])>
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <label class="space-y-1 text-sm font-medium"><span>Search repair, SKU, product or damage reference</span><input type="search" wire:model.live.debounce.350ms="search" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" placeholder="Search repairs"></label>
            <label class="space-y-1 text-sm font-medium"><span>Technician / Service Provider</span><input type="search" wire:model.live.debounce.350ms="technician" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" placeholder="All technicians"></label>
            <label class="space-y-1 text-sm font-medium"><span>Status</span><select wire:model.live="status" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="">All statuses</option>@foreach($statuses as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
            <label class="space-y-1 text-sm font-medium"><span>Marketplace</span><select wire:model.live="platformId" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="">All marketplaces</option>@foreach($platforms as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select></label>
            <label class="space-y-1 text-sm font-medium"><span>Responsible Employee</span><select wire:model.live="assignedToUserId" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="">All employees</option>@foreach($assignees as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select></label>
            <label class="space-y-1 text-sm font-medium"><span>Received From</span><input type="date" wire:model.live="from" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"></label>
            <label class="space-y-1 text-sm font-medium"><span>Received To</span><input type="date" wire:model.live="to" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"></label>
            <div class="flex items-end"><x-filament::button color="gray" wire:click="resetFilters" class="w-full">Reset Filters</x-filament::button></div>
        </div>
    </x-filament::section>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <table class="w-full min-w-[1850px] text-sm">
            <thead class="bg-gray-50 text-left dark:bg-white/5"><tr>
                @foreach(['Repair Ref', 'Product / SKU', 'Qty', 'Repair Type', 'Original Damage', 'Return / Order', 'Technician', 'Assigned To', 'Sent', 'Expected Return', 'Days With Technician', 'Status', 'Final Condition', 'Destination SKU', 'Action'] as $heading)
                    <th class="whitespace-nowrap px-4 py-3 font-medium">{{ $heading }}</th>
                @endforeach
            </tr></thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @forelse($rows as $repair)
                    @php
                        $internal = $repair->isInternalCompanyOwnedRepair();
                        $sent = $repair->sentToTechnicianEvent?->changed_at ?? $repair->sent_to_technician_at;
                        $detailsUrl = $internal
                            ? \App\Filament\Resources\InternalRepairs\InternalRepairResource::getUrl('view', ['record' => $repair])
                            : \App\Filament\Resources\WarrantyRepairs\WarrantyRepairResource::getUrl('view', ['record' => $repair]);
                    @endphp
                    <tr wire:key="repair-management-{{ $repair->id }}">
                        <td class="whitespace-nowrap px-4 py-3 font-semibold"><a href="{{ $detailsUrl }}" class="text-primary-600 hover:underline">{{ $repair->reference }}</a></td>
                        <td class="px-4 py-3"><div>{{ $repair->product?->name }}</div><div class="text-xs text-gray-500">{{ $repair->product?->sku }}</div></td>
                        <td class="px-4 py-3 text-right">{{ $repair->quantity }}</td>
                        <td class="px-4 py-3">{{ $internal ? 'Company-Owned' : 'Customer / Warranty' }}</td>
                        <td class="px-4 py-3">{{ $repair->damagedStockEvent?->reference ?? ($repair->isLegacyDamagedRepair() ? 'Old Damaged Stock' : '—') }}</td>
                        <td class="px-4 py-3">{{ $repair->damagedStockEvent?->customerReturn?->reference ?? $repair->customerReturn?->reference ?? $repair->damagedStockEvent?->order?->reference ?? $repair->order?->reference ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $repair->service_provider ?: '—' }}</td>
                        <td class="px-4 py-3">{{ $repair->assignedTo?->name ?? 'Unassigned' }}</td>
                        <td class="whitespace-nowrap px-4 py-3">{{ $sent?->format('d M Y') ?? '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-3">{{ $repair->expected_return_at?->format('d M Y') ?? '—' }}</td>
                        <td class="px-4 py-3 text-right">{{ $sent ? (int) $sent->startOfDay()->diffInDays(($repair->received_back_at ?? now())->startOfDay()).' days' : '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-3">{{ $repair->status->getLabel() }}</td>
                        <td class="px-4 py-3">{{ $repair->stockClassification?->condition?->label() ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $repair->stockClassification?->destinationProduct?->sku ?? '—' }}</td>
                        <td class="px-4 py-3"><a href="{{ $detailsUrl }}" class="text-primary-600 hover:underline">Open Repair</a></td>
                    </tr>
                @empty
                    <tr><td colspan="15" class="px-4 py-10 text-center text-gray-500">No repairs match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $rows->links() }}
</x-filament-panels::page>
