<x-filament-panels::page>
    <x-filament::section>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Assigned stock is reported from Allocation Balances. Responsibility describes who handles work; it does not establish stock ownership.
        </p>
        @if (\App\Filament\Pages\Administration\InventoryAllocations::canAccess())
            <div class="mt-3">
                <x-filament::button tag="a" size="sm" color="gray" :href="\App\Filament\Pages\Administration\InventoryAllocations::getUrl()">Open Allocation Management</x-filament::button>
            </div>
        @endif
    </x-filament::section>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        @foreach ([
            'Total Held' => $metrics['total_held'],
            'Available' => $metrics['available'],
            'Reserved' => $metrics['reserved'],
            'Models' => $metrics['models'],
            'Unassigned / System' => $metrics['unassigned'],
        ] as $label => $value)
            <x-filament::section compact>
                <div class="text-xs font-medium text-gray-500">{{ $label }}</div>
                <div class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($value) }}</div>
                @if ($label === 'Total Held')<div class="mt-1 text-xs text-gray-500">Includes reserved units</div>@endif
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section heading="Stock by Holder">
        @if ($holderSummaries->isEmpty())
            <p class="py-4 text-sm text-gray-500">No allocated stock matches these filters.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[40rem] divide-y divide-gray-200 text-sm dark:divide-white/10">
                    <thead><tr class="text-left text-xs uppercase text-gray-500"><th class="px-3 py-2">Holder</th><th class="px-3 py-2 text-right">Total Held</th><th class="px-3 py-2 text-right">Available</th><th class="px-3 py-2 text-right">Reserved</th><th class="px-3 py-2 text-right">Models</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($holderSummaries as $holder)
                            <tr wire:key="stock-holder-{{ $holder->account_id }}">
                                <td class="px-3 py-2"><button type="button" class="font-medium text-primary-600 hover:underline" wire:click="selectHolder('{{ $holder->account_id }}')">{{ $this->holderLabel($holder) }}</button></td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ number_format($holder->total_held) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ number_format($holder->total_held - $holder->reserved_quantity) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ number_format($holder->reserved_quantity) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ number_format($holder->model_count) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section heading="Allocation Details" description="Total Held equals allocated quantity; Available is Total Held minus Reserved.">
        <div class="grid items-end gap-3 sm:grid-cols-2 xl:grid-cols-6">
            <label><span class="mb-1 block text-xs font-medium">Holder</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="holderId"><option value="">All holders</option>@foreach ($holderOptions as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
            <label><span class="mb-1 block text-xs font-medium">Product / Model</span><x-filament::input.wrapper><x-filament::input type="search" wire:model.live.debounce.300ms="search" placeholder="SKU or product title" /></x-filament::input.wrapper></label>
            <label><span class="mb-1 block text-xs font-medium">Brand</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="brandId"><option value="">All brands</option>@foreach ($brandOptions as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
            <label><span class="mb-1 block text-xs font-medium">Category</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="categoryId"><option value="">All categories</option>@foreach ($categoryOptions as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
            <label><span class="mb-1 block text-xs font-medium">Warehouse</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="warehouseId"><option value="">All warehouses</option>@foreach ($warehouseOptions as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
            <div class="flex items-center gap-2 pb-2"><input id="unassignedOnly" type="checkbox" wire:model.live="unassignedOnly" class="rounded border-gray-300"><label for="unassignedOnly" class="text-sm">Unassigned only</label></div>
        </div>
        <div class="mt-3 flex justify-end"><x-filament::button size="sm" color="gray" wire:click="resetFilters">Clear filters</x-filament::button></div>

        <div class="mt-4 overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
            <table class="w-full min-w-[68rem] divide-y divide-gray-200 text-sm dark:divide-white/10">
                <thead class="bg-gray-50 dark:bg-white/5"><tr>@foreach (['Holder','SKU','Product / Model','Brand','Category','Warehouse','Available','Reserved','Total Held'] as $heading)<th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide">{{ $heading }}</th>@endforeach</tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($balances as $balance)
                        @php($product = $balance->inventory?->product)
                        <tr wire:key="stock-balance-{{ $balance->id }}">
                            <td class="px-3 py-2">{{ $this->holderLabel($balance->account) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 font-medium">{{ $product?->sku ?? '—' }}</td>
                            <td class="max-w-72 px-3 py-2">{{ $product?->name ?? 'Product unavailable' }}@if ($product?->model)<span class="block text-xs text-gray-500">{{ $product->model }}</span>@endif</td>
                            <td class="px-3 py-2">{{ $product?->brandRelation?->name ?? $product?->brand ?? '—' }}</td>
                            <td class="px-3 py-2">{{ $product?->categoryRelation?->name ?? $product?->category ?? '—' }}</td>
                            <td class="px-3 py-2">{{ $balance->inventory?->warehouse?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format($balance->availableQuantity()) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format($balance->reserved_quantity) }}</td>
                            <td class="px-3 py-2 text-right font-medium tabular-nums">{{ number_format($balance->allocated_quantity) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-3 py-8 text-center text-sm text-gray-500">No allocation balances match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $balances->links() }}</div>
    </x-filament::section>
</x-filament-panels::page>
