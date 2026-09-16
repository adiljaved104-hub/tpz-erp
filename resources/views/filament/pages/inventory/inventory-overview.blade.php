<x-filament-panels::page>
    <style>
        .inventory-summary-grid, .inventory-product-metrics { display: grid; grid-template-columns: minmax(0, 1fr); gap: .75rem; min-width: 0; }
        @media (min-width: 640px) { .inventory-summary-grid, .inventory-product-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (min-width: 768px) { .inventory-summary-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (min-width: 1024px) { .inventory-summary-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } .inventory-product-metrics { grid-template-columns: repeat(5, minmax(0, 1fr)); } }
    </style>
    <div class="space-y-4">
        <div class="inventory-summary-grid" data-testid="inventory-summary-grid">
            @foreach (['total_company_stock' => 'Total Company Stock', 'on_location' => 'On Location', 'in_transit' => 'Normal In Transit', 'return_in_transit' => 'Return-to-Company In Transit', 'damaged' => 'Damaged', 'marketplace_non_sellable' => 'Marketplace Non-Sellable', 'qc_pending' => 'QC Pending'] as $key => $label)
                <x-filament::section compact><div class="text-2xl font-semibold leading-none">{{ number_format($summary[$key]) }}</div><div class="mt-1 text-xs text-gray-500">{{ $label }}</div></x-filament::section>
            @endforeach
        </div>

        <x-filament::section heading="Find Inventory" description="Filter the company inventory visible in your authorized scope.">
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <label class="min-w-0 md:col-span-2 xl:col-span-4"><span class="mb-1.5 block text-sm font-medium">Search</span><x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass"><x-filament::input wire:model.live.debounce.250ms="search" type="search" placeholder="Search by SKU or product name" /></x-filament::input.wrapper></label>
                <label class="min-w-0"><span class="mb-1.5 block text-sm font-medium">Warehouse / Location</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="warehouse"><option value="">All Locations</option>@foreach ($filterOptions['warehouses'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                <label class="min-w-0"><span class="mb-1.5 block text-sm font-medium">Platform</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="platform"><option value="">All Platforms</option>@foreach ($filterOptions['platforms'] as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                <label class="min-w-0"><span class="mb-1.5 block text-sm font-medium">Stock Status</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="stockStatus"><option value="">All Stock</option><option value="in_stock">In Stock</option><option value="low_stock">Low Stock</option><option value="out_of_stock">Out of Stock</option></x-filament::input.select></x-filament::input.wrapper></label>
                <div class="flex items-end"><x-filament::button color="gray" icon="heroicon-m-x-mark" wire:click="resetInventoryFilters" class="w-full justify-center">Clear filters</x-filament::button></div>
            </div>
        </x-filament::section>

        @forelse ($products as $row)
            <x-filament::section>
                <x-slot name="heading"><span class="line-clamp-2" title="{{ $row['product']->name }}">{{ $row['product']->sku }} · {{ \Illuminate\Support\Str::limit($row['product']->name, 72) }}</span></x-slot>

                <div class="inventory-product-metrics mb-4" data-testid="inventory-product-metrics">
                    @foreach (['total_owned' => 'Total Owned', 'available' => 'Available', 'reserved' => 'Reserved', 'sellable' => 'Sellable', 'damaged' => 'Damaged', 'marketplace_non_sellable' => 'Marketplace Non-Sellable', 'qc_pending' => 'QC Pending', 'in_transit' => 'Normal In Transit', 'return_in_transit' => 'Return-to-Company In Transit'] as $key => $label)
                        <div class="min-w-0 rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/5"><div class="truncate text-xs text-gray-500" title="{{ $label }}">{{ $label }}</div><div class="font-semibold">{{ number_format($row[$key]) }}</div></div>
                    @endforeach
                    @isset($row['total_inventory_value'])
                        <div><div class="text-xs text-gray-500">Total Inventory Value</div><div class="font-semibold">AED {{ number_format((float) $row['total_inventory_value'], 2) }}</div></div>
                    @endisset
                </div>

                <details>
                    <summary class="cursor-pointer font-medium">Location breakdown ({{ $row['locations']->count() }})</summary>
                    <div class="mt-3 max-w-full overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                            <thead><tr>
                                @foreach (['Location', 'Type', 'Platform / Tag', 'Available', 'Reserved', 'Sellable', 'Damaged', 'Marketplace Non-Sellable', 'QC Pending', 'Location Total Owned'] as $heading)
                                    <th class="whitespace-nowrap px-3 py-2 text-left">{{ $heading }}</th>
                                @endforeach
                                @if ($row['locations']->first() && array_key_exists('average_cost', $row['locations']->first()))
                                    <th class="whitespace-nowrap px-3 py-2 text-right">Average Cost</th>
                                    <th class="whitespace-nowrap px-3 py-2 text-right">Inventory Value</th>
                                @endif
                            </tr></thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                @foreach ($row['locations'] as $location)
                                    <tr>
                                        <td class="whitespace-nowrap px-3 py-2">{{ $location['name'] }} ({{ $location['code'] }}) @unless($location['active'])<span class="text-xs text-gray-500">Inactive</span>@endunless</td>
                                        <td class="whitespace-nowrap px-3 py-2">{{ $location['type'] }}</td>
                                        <td class="whitespace-nowrap px-3 py-2">{{ collect([$location['platform'], $location['fulfillment_tag']])->filter()->join(' · ') ?: '—' }}</td>
                                        @foreach (['available', 'reserved', 'sellable', 'damaged', 'marketplace_non_sellable', 'qc_pending', 'location_total_owned'] as $key)<td class="px-3 py-2 text-right">{{ number_format($location[$key]) }}</td>@endforeach
                                        @isset($location['average_cost'])
                                            <td class="whitespace-nowrap px-3 py-2 text-right">AED {{ number_format((float) ($location['average_cost'] ?? 0), 2) }}</td>
                                            <td class="whitespace-nowrap px-3 py-2 text-right">AED {{ number_format((float) $location['inventory_value'], 2) }}</td>
                                        @endisset
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            </x-filament::section>
        @empty
            <x-filament::section>
                {{ $hasAuthorizedInventory
                    ? 'No inventory matches the current search and filters.'
                    : ($hasCompanyInventory ? 'No inventory is available in your authorized Responsibility scope.' : 'No inventory balances exist yet.') }}
            </x-filament::section>
        @endforelse

        @if ($products->hasPages())
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <label class="flex items-center gap-2 text-sm text-gray-500"><span>Per page</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="perPage"><option value="10">10</option><option value="25">25</option><option value="50">50</option></x-filament::input.select></x-filament::input.wrapper></label>
                <div class="min-w-0">{{ $products->onEachSide(1)->links() }}</div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
