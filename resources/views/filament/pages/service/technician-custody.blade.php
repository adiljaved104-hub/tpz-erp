<x-filament-panels::page>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        @foreach([
            'Total Units With Technicians' => $summary['total'],
            'In Repair' => $summary['in_repair'],
            'Waiting for Parts' => $summary['waiting_for_parts'],
            'Overdue' => $summary['overdue'],
            'Ready / Repair Completed' => $summary['repair_completed'],
        ] as $label => $value)
            <x-filament::section compact>
                <div class="text-sm text-gray-500">{{ $label }}</div>
                <div class="text-2xl font-semibold">{{ number_format($value) }}</div>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
            <label class="space-y-1 text-sm font-medium"><span>Technician / Service Provider</span><select wire:model.live="technician" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="">All</option>@foreach($technicians as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
            <label class="space-y-1 text-sm font-medium"><span>Status</span><select wire:model.live="status" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="">All</option>@foreach($statuses as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
            <label class="space-y-1 text-sm font-medium"><span>Platform</span><select wire:model.live="platformId" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="">All</option>@foreach($platforms as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select></label>
            <label class="space-y-1 text-sm font-medium"><span>Product</span><select wire:model.live="productId" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="">All</option>@foreach($products as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select></label>
            <label class="space-y-1 text-sm font-medium"><span>Assigned To</span><select wire:model.live="assignedToUserId" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="">All</option>@foreach($assignees as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select></label>
            <div class="flex items-end"><x-filament::button color="gray" wire:click="resetFilters" class="w-full">Reset Filters</x-filament::button></div>
        </div>
    </x-filament::section>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <table class="w-full min-w-[1750px] text-sm">
            <thead class="bg-gray-50 text-left dark:bg-white/5"><tr>
                @foreach(['Warranty Ref','Case Type','Product / SKU','Qty','Technician / Service Provider','Sent Date','Days With Technician','Expected Return','SLA Due','Status','Assigned To','Platform','Order / Return'] as $heading)
                    <th class="whitespace-nowrap px-4 py-3 font-medium">{{ $heading }}</th>
                @endforeach
            </tr></thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @forelse($rows as $row)
                    <tr wire:key="technician-custody-{{ $row['id'] }}">
                        <td class="whitespace-nowrap px-4 py-3 font-medium"><a class="text-primary-600 hover:underline" href="{{ $row['case_url'] }}">{{ $row['reference'] }}</a></td>
                        <td class="whitespace-nowrap px-4 py-3">{{ $row['case_type'] }}</td>
                        <td class="px-4 py-3"><div>{{ $row['product_name'] }}</div><div class="text-xs text-gray-500">{{ $row['sku'] }}</div></td>
                        <td class="px-4 py-3 text-right">{{ number_format($row['quantity']) }}</td>
                        <td class="px-4 py-3">{{ $row['service_provider'] ?: 'Not recorded' }}</td>
                        <td class="whitespace-nowrap px-4 py-3">{{ $row['sent_date'] ?? 'Not recorded' }}</td>
                        <td class="px-4 py-3 text-right">{{ $row['days_with_technician'] === null ? '—' : number_format($row['days_with_technician']).' days' }}</td>
                        <td class="whitespace-nowrap px-4 py-3">{{ $row['expected_return'] ?? 'Not recorded' }}</td>
                        <td class="whitespace-nowrap px-4 py-3">{{ $row['sla_due'] }}</td>
                        <td class="whitespace-nowrap px-4 py-3">{{ $row['status_label'] }}</td>
                        <td class="px-4 py-3">{{ $row['assigned_to_name'] ?? 'Unassigned' }}</td>
                        <td class="px-4 py-3">{{ $row['platform_name'] ?? 'Internal / Manual' }}</td>
                        <td class="whitespace-nowrap px-4 py-3">{{ $row['context_reference'] ?? 'Not recorded' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="13" class="px-4 py-10 text-center text-gray-500">No units are currently recorded with technicians.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $rows->links() }}
</x-filament-panels::page>
