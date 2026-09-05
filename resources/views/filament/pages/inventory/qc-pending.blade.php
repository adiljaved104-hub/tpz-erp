<x-filament-panels::page>
    <div class="grid gap-3 sm:grid-cols-3">
        <x-filament::section compact>
            <div class="text-sm text-gray-500">Pending QC Returns</div>
            <div class="text-2xl font-semibold">{{ number_format($summary['returns']) }}</div>
        </x-filament::section>
        <x-filament::section compact>
            <div class="text-sm text-gray-500">Pending QC Units</div>
            <div class="text-2xl font-semibold">{{ number_format($summary['units']) }}</div>
        </x-filament::section>
        <x-filament::section compact>
            <div class="text-sm text-gray-500">Oldest Pending</div>
            <div class="text-2xl font-semibold">{{ $summary['oldest'] ?? '—' }}</div>
        </x-filament::section>
    </div>

    <x-filament::section>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
            <label class="space-y-1 text-sm font-medium">
                <span>Product / SKU</span>
                <input type="search" wire:model.live.debounce.350ms="search" placeholder="Search Product or SKU" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
            </label>
            <label class="space-y-1 text-sm font-medium">
                <span>Receiving Location</span>
                <select wire:model.live="warehouseId" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                    <option value="">All</option>
                    @foreach ($warehouses as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
            </label>
            <label class="space-y-1 text-sm font-medium">
                <span>Platform</span>
                <select wire:model.live="platformId" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                    <option value="">All</option>
                    @foreach ($platforms as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
            </label>
            <label class="space-y-1 text-sm font-medium">
                <span>Return Reason</span>
                <select wire:model.live="returnReason" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                    <option value="">All</option>
                    @foreach ($reasons as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
            </label>
            <label class="space-y-1 text-sm font-medium">
                <span>Received From</span>
                <input type="date" wire:model.live="receivedFrom" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
            </label>
            <label class="space-y-1 text-sm font-medium">
                <span>Minimum Age</span>
                <select wire:model.live="minDaysPending" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                    <option value="">Any</option>
                    @foreach ([1, 3, 7, 14, 30] as $days)<option value="{{ $days }}">{{ $days }}+ days</option>@endforeach
                </select>
            </label>
        </div>
        <div class="mt-3"><x-filament::button color="gray" wire:click="resetFilters">Clear filters</x-filament::button></div>
    </x-filament::section>

    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
        <table class="min-w-[1500px] w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
            <thead class="bg-gray-50 dark:bg-white/5"><tr>
                @foreach (['Return','Order','SKU','Product / Model','Qty Received','Qty Inspected','Qty Pending QC','Return Reason','Platform','Fulfilled From','Receiving Location','Received At','Days Pending','Received By','Action'] as $heading)
                    <th class="whitespace-nowrap px-3 py-3 text-left font-semibold">{{ $heading }}</th>
                @endforeach
            </tr></thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse ($rows as $row)
                    <tr wire:key="qc-pending-{{ $row['id'] }}">
                        <td class="whitespace-nowrap px-3 py-3 font-medium">{{ $row['return_reference'] }}</td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $row['order_reference'] }}</td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $row['sku'] }}</td>
                        <td class="max-w-72 px-3 py-3"><span class="line-clamp-2" title="{{ $row['product_name'] }}">{{ \Illuminate\Support\Str::limit($row['product_name'], 72) }}</span></td>
                        <td class="px-3 py-3 text-right">{{ number_format($row['received_quantity']) }}</td>
                        <td class="px-3 py-3 text-right">{{ number_format($row['inspected_quantity']) }}</td>
                        <td class="px-3 py-3 text-right font-semibold">{{ number_format($row['pending_quantity']) }}</td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $row['return_reason_label'] }}</td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $row['platform'] ?? '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $row['fulfilled_from'] }}</td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $row['receiving_location'] }}</td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $row['received_at_display'] }}</td>
                        <td class="px-3 py-3 text-right">{{ number_format($row['days_pending']) }}</td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $row['received_by'] ?? '—' }}</td>
                        <td class="sticky right-0 bg-white px-3 py-3 shadow-[-4px_0_8px_rgba(0,0,0,.06)] dark:bg-gray-900">
                            @if ($canInspect)
                                <x-filament::button tag="a" :href="$this->inspectUrl($row['source_kind'], $row['source_id'])" size="sm">Inspect</x-filament::button>
                            @else
                                <span class="text-gray-500">View only</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="15" class="px-4 py-10 text-center text-gray-500">No Customer Return items are waiting for QC.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $rows->links() }}
</x-filament-panels::page>
