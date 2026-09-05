<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-3">
        <x-filament::section compact><div class="text-sm text-gray-500">Damaged Items</div><div class="text-2xl font-semibold">{{ $summary['items'] }}</div></x-filament::section>
        <x-filament::section compact><div class="text-sm text-gray-500">Damaged Units</div><div class="text-2xl font-semibold">{{ $summary['units'] }}</div></x-filament::section>
        <x-filament::section compact><div class="text-sm text-gray-500">Oldest Damaged</div><div class="text-2xl font-semibold">{{ $summary['oldest'] ?? 'Not recorded' }}</div></x-filament::section>
    </div>

    <x-filament::section>
        <div class="grid gap-3 md:grid-cols-6">
            <x-filament::input.wrapper><x-filament::input type="search" wire:model.live.debounce.400ms="search" placeholder="Product or SKU" /></x-filament::input.wrapper>
            <x-filament::input.wrapper><x-filament::input.select wire:model.live="warehouseId"><option value="">All locations</option>@foreach($warehouses as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>
            <x-filament::input.wrapper><x-filament::input.select wire:model.live="source"><option value="">All sources</option>@foreach($sources as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>
            <x-filament::input.wrapper><x-filament::input.select wire:model.live="platformId"><option value="">All platforms</option>@foreach($platforms as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>
            <x-filament::input.wrapper><x-filament::input.select wire:model.live="status">@foreach($statuses as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>
            <x-filament::button color="gray" wire:click="resetFilters">Reset</x-filament::button>
        </div>
    </x-filament::section>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <table class="w-full min-w-[1050px] text-sm">
            <thead class="bg-gray-50 text-left dark:bg-white/5"><tr>
                @foreach(['SKU','Product / Model','Qty','Location','Source','Platform','Related Return / Order','Reason','Damaged Date','Days Damaged','Status',''] as $heading)
                    <th class="px-4 py-3 font-medium">{{ $heading }}</th>
                @endforeach
            </tr></thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @forelse($rows as $row)
                    <tr>
                        <td class="whitespace-nowrap px-4 py-3 font-medium">{{ $row['sku'] }}</td><td class="max-w-72 px-4 py-3"><span class="line-clamp-2" title="{{ $row['product_name'] }}">{{ \Illuminate\Support\Str::limit($row['product_name'], 72) }}</span></td>
                        <td class="px-4 py-3">{{ $row['quantity'] }}</td><td class="px-4 py-3">{{ $row['location'] }}</td>
                        <td class="px-4 py-3">{{ $row['source_label'] }}</td><td class="px-4 py-3">{{ $row['platform'] ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $row['related_reference'] ?? '—' }}</td><td class="px-4 py-3">{{ $row['reason'] }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $row['damaged_date'] ?? 'Not recorded' }}</td><td class="px-4 py-3">{{ $row['days_damaged'] ?? '—' }}</td>
                        <td class="px-4 py-3"><x-filament::badge color="gray">{{ $row['status_label'] }}</x-filament::badge></td>
                        <td class="px-4 py-3"><div class="flex items-center gap-2"><details><summary class="cursor-pointer font-medium text-primary-600">View</summary><div class="mt-2 min-w-64 space-y-1 text-gray-600 dark:text-gray-300"><div><strong>Product:</strong> {{ $row['sku'] }} — {{ $row['product_name'] }}</div><div><strong>Remaining Quantity:</strong> {{ $row['quantity'] }}</div><div><strong>Location:</strong> {{ $row['location'] }}</div><div><strong>Source:</strong> {{ $row['source_label'] }}</div><div><strong>Reason:</strong> {{ $row['reason'] }}</div><div><strong>Date:</strong> {{ $row['damaged_date'] ?? 'Not recorded' }}</div><div><strong>Reference:</strong> {{ $row['related_reference'] ?? 'Not recorded' }}</div><div><strong>Handled by:</strong> {{ $row['reported_by'] ?? 'Not recorded' }}</div></div></details>@if($canSendToRepair && $row['row_kind'] === 'event' && $row['status'] === 'damaged' && $row['available_repair_quantity'] > 0){{ ($this->sendToRepairAction)(['damageId' => $row['id']]) }}@elseif($canSendToRepair && $row['row_kind'] === 'legacy' && $row['available_repair_quantity'] > 0){{ ($this->startLegacyRepairAction)(['inventoryId' => abs($row['id'])]) }}@endif</div></td>
                    </tr>
                @empty
                    <tr><td colspan="12" class="px-4 py-8 text-center text-gray-500">No damaged items match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $rows->links() }}
    <x-filament-actions::modals />
</x-filament-panels::page>
