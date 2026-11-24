<x-filament::section>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">Stock Ownership</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400">Live allocation ledger balances. Total held includes reserved stock; available excludes reserved stock.</p>
        </div>
        <x-filament::badge color="info">{{ number_format($ownershipBalances->total()) }} records</x-filament::badge>
    </div>

    <div class="mb-4 grid items-end gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <label>
            <span class="mb-1 block text-xs font-medium">SKU / Product</span>
            <x-filament::input.wrapper><x-filament::input type="search" wire:model.live.debounce.300ms="ownershipSearch" placeholder="Search SKU or product title" /></x-filament::input.wrapper>
        </label>
        <label>
            <span class="mb-1 block text-xs font-medium">Stock Holder</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="ownershipHolderId">
                    <option value="">All holders</option>
                    @foreach($ownershipHolders as $holder)
                        <option value="{{ $holder->id }}">{{ \App\Filament\Pages\Inventory\StockByHolder::canAccess() ? (data_get($holder, 'employee.name') ?? data_get($holder, 'team.name') ?? $holder->name) : '' }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
        <label>
            <span class="mb-1 block text-xs font-medium">Brand</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="ownershipBrandId">
                    <option value="">All brands</option>
                    @foreach($ownershipBrands as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
        <label>
            <span class="mb-1 block text-xs font-medium">Warehouse</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="ownershipWarehouseId">
                    <option value="">All warehouses</option>
                    @foreach($ownershipWarehouses as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
    </div>

    <div class="mb-3 flex justify-end">
        <x-filament::button size="sm" color="gray" wire:click="clearOwnershipFilters">Clear filters</x-filament::button>
    </div>

    <div class="tpz-native-record-table-wrap">
        <table class="tpz-native-record-table">
            <thead>
                <tr><th>Holder</th><th>SKU / Product</th><th>Brand</th><th>Warehouse</th><th>Available</th><th>Reserved</th><th>Total Held</th></tr>
            </thead>
            <tbody>
                @forelse($ownershipBalances as $balance)
                    @php($product = $balance->inventory?->product)
                    @php($holder = $balance->account)
                    <tr wire:key="workspace-ownership-{{ $balance->id }}">
                        <td>{{ data_get($holder, 'employee.name') ?? data_get($holder, 'team.name') ?? $holder?->name ?? 'Unassigned / System' }}</td>
                        <td><strong>{{ $product?->sku ?? '—' }}</strong><span class="block whitespace-normal">{{ $product?->name ?? 'Product unavailable' }}</span></td>
                        <td>{{ $product?->brandRelation?->name ?? $product?->brand ?? '—' }}</td>
                        <td>{{ $balance->inventory?->warehouse?->name ?? '—' }}</td>
                        <td class="tabular-nums">{{ number_format($balance->availableQuantity()) }}</td>
                        <td class="tabular-nums">{{ number_format($balance->reserved_quantity) }}</td>
                        <td class="tabular-nums">{{ number_format($balance->allocated_quantity) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-8 text-center text-gray-500">No stock ownership records match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $ownershipBalances->links() }}</div>
</x-filament::section>
