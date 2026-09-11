<x-filament-panels::page>
    <div class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-start justify-between gap-3">
                    <div><p class="text-sm font-medium text-gray-500 dark:text-gray-400">My Products</p><p class="mt-2 text-3xl font-bold tracking-tight text-gray-950 dark:text-white">{{ number_format($summary['products']) }}</p></div>
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-950/40 dark:text-primary-400"><x-filament::icon icon="heroicon-o-cube" class="size-5" /></span>
                </div>
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">Products visible through my responsibilities</p>
            </div>
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-start justify-between gap-3">
                    <div><p class="text-sm font-medium text-gray-500 dark:text-gray-400">Sellable / Usable Stock</p><p class="mt-2 text-3xl font-bold tracking-tight text-gray-950 dark:text-white">{{ number_format($summary['usable']) }}</p></div>
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-success-50 text-success-600 dark:bg-success-950/40 dark:text-success-400"><x-filament::icon icon="heroicon-o-circle-stack" class="size-5" /></span>
                </div>
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">Within my current access and allocations</p>
            </div>
            <button type="button" wire:click="applyStockStatus('low_stock')" @class(['rounded-xl p-5 text-left shadow-sm ring-1 transition focus:outline-none focus:ring-2 focus:ring-warning-500', 'bg-warning-50 ring-warning-400 dark:bg-warning-950/30' => $stockStatus === 'low_stock', 'bg-white ring-gray-950/5 hover:ring-warning-300 dark:bg-gray-900 dark:ring-white/10' => $stockStatus !== 'low_stock'])>
                <div class="flex items-start justify-between gap-3">
                    <div><p class="text-sm font-medium text-gray-500 dark:text-gray-400">Low Stock</p><p class="mt-2 text-3xl font-bold tracking-tight text-warning-600 dark:text-warning-400">{{ number_format($summary['low']) }}</p></div>
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-warning-100 text-warning-600 dark:bg-warning-950/60 dark:text-warning-400"><x-filament::icon icon="heroicon-o-exclamation-triangle" class="size-5" /></span>
                </div>
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">1 employee-usable unit · Click to filter</p>
            </button>
            <button type="button" wire:click="applyStockStatus('out_of_stock')" @class(['rounded-xl p-5 text-left shadow-sm ring-1 transition focus:outline-none focus:ring-2 focus:ring-danger-500', 'bg-danger-50 ring-danger-400 dark:bg-danger-950/30' => $stockStatus === 'out_of_stock', 'bg-white ring-gray-950/5 hover:ring-danger-300 dark:bg-gray-900 dark:ring-white/10' => $stockStatus !== 'out_of_stock'])>
                <div class="flex items-start justify-between gap-3">
                    <div><p class="text-sm font-medium text-gray-500 dark:text-gray-400">Out of Stock</p><p class="mt-2 text-3xl font-bold tracking-tight text-danger-600 dark:text-danger-400">{{ number_format($summary['out']) }}</p></div>
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-danger-100 text-danger-600 dark:bg-danger-950/60 dark:text-danger-400"><x-filament::icon icon="heroicon-o-x-circle" class="size-5" /></span>
                </div>
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">No employee-usable stock · Click to filter</p>
            </button>
        </div>

        @if ($responsibilities->isNotEmpty())
            <x-filament::section heading="My Responsibilities" description="The active scopes that determine which Products and inventory you can work with.">
                <div class="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 dark:divide-white/5 dark:border-white/10">
                    @foreach ($responsibilities as $scope)
                        <div class="flex min-w-0 flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3">
                            <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                                <span class="max-w-full truncate text-sm font-semibold text-gray-950 dark:text-white" title="{{ $scope['scope'] }}">{{ $scope['scope'] }}</span>
                                @if ($scope['platform'])
                                    <x-filament::badge color="info" size="sm">{{ $scope['platform'] }}</x-filament::badge>
                                @endif
                                <x-filament::badge color="gray" size="sm">{{ $scope['type'] }}</x-filament::badge>
                                @if ($scope['quantity'] !== null)
                                    <x-filament::badge color="warning" size="sm">{{ $scope['remaining'] }} / {{ $scope['quantity'] }} remaining</x-filament::badge>
                                @endif
                            </div>
                            <span class="shrink-0 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $scope['reference'] }}</span>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        <x-filament::section heading="My Products" description="Search and filter only the inventory available through your active responsibilities.">
            <div class="mb-5 space-y-4">
                <div>
                    <label class="block min-w-0">
                        <span class="mb-1.5 block text-sm font-medium">Search Products</span>
                        <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                            <x-filament::input wire:model.live.debounce.250ms="search" type="search" placeholder="Search by SKU, product name or model" />
                        </x-filament::input.wrapper>
                    </label>
                </div>
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <label class="min-w-0"><span class="mb-1.5 block text-sm font-medium">Brand</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="brand"><option value="">All Brands</option>@foreach ($filterOptions['brands'] as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                    <label class="min-w-0"><span class="mb-1.5 block text-sm font-medium">Category</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="category"><option value="">All Categories</option>@foreach ($filterOptions['categories'] as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                    <label class="min-w-0"><span class="mb-1.5 block text-sm font-medium">Platform</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="platform"><option value="">All Platforms</option>@foreach ($filterOptions['platforms'] as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                    <label class="min-w-0"><span class="mb-1.5 block text-sm font-medium">Warehouse</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="warehouse"><option value="">All Locations</option>@foreach ($filterOptions['warehouses'] as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach @if ($filterOptions['hasNoBalance'])<option value="__none">No balance</option>@endif</x-filament::input.select></x-filament::input.wrapper></label>
                    <label class="min-w-0"><span class="mb-1.5 block text-sm font-medium">Stock Status</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="stockStatus"><option value="">All Stock</option><option value="in_stock">In Stock</option><option value="low_stock">Low Stock</option><option value="out_of_stock">Out of Stock</option></x-filament::input.select></x-filament::input.wrapper></label>
                    <label class="min-w-0"><span class="mb-1.5 block text-sm font-medium">Allocation</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="allocation"><option value="">All Allocations</option><option value="shared">Shared Responsibility</option><option value="quantity">Quantity Allocated</option><option value="exhausted">Allocation Exhausted</option></x-filament::input.select></x-filament::input.wrapper></label>
                    <label class="min-w-0 xl:col-span-2"><span class="mb-1.5 block text-sm font-medium">Visible Because</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="visibleBecause"><option value="">All Responsibilities</option>@foreach ($filterOptions['reasons'] as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                    <div class="flex items-end"><x-filament::button color="gray" icon="heroicon-m-x-mark" wire:click="resetInventoryFilters" class="w-full justify-center">Clear filters</x-filament::button></div>
                </div>
            </div>

            @if ($inventoryRows->isEmpty())
                <div class="rounded-lg border border-dashed border-gray-300 px-4 py-10 text-center dark:border-white/15">
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ $hasAuthorizedInventory ? 'No inventory matches the current search and filters.' : 'No active Product responsibilities.' }}</p>
                    @if ($hasAuthorizedInventory)<p class="mt-1 text-xs text-gray-500">Try clearing one or more filters.</p>@endif
                </div>
            @else
                <div class="hidden overflow-x-auto rounded-xl border border-gray-200 lg:block dark:border-white/10">
                    <table class="w-full min-w-[1040px] table-fixed divide-y divide-gray-200 text-sm dark:divide-white/10">
                        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:bg-white/5 dark:text-gray-300">
                            <tr><th class="w-72 px-4 py-3">Product</th><th class="w-32 px-4 py-3">Platform</th><th class="w-32 px-4 py-3">Location</th><th class="w-20 px-4 py-3 text-center">Usable</th><th class="w-20 px-4 py-3 text-center">Reserved</th><th class="w-28 px-4 py-3 text-center">My Allocation</th><th class="w-32 px-4 py-3">Status</th><th class="px-4 py-3">Visible Because</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($inventoryRows as $row)
                                <tr class="align-top">
                                    <td class="px-4 py-3"><p class="line-clamp-2 font-medium leading-5 text-gray-950 dark:text-white" title="{{ $row->name }}">{{ $row->name }}</p><p class="mt-1 truncate text-xs font-medium text-gray-500">{{ $row->sku }}@if ($row->brand) · {{ $row->brand }}@endif @if ($row->model) · {{ $row->model }}@endif</p><details class="mt-2 text-xs text-gray-500"><summary class="cursor-pointer font-medium text-primary-600 dark:text-primary-400">Inventory details</summary><dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1"><dt>Available</dt><dd>{{ $row->available }}</dd><dt>Damaged</dt><dd>{{ $row->damaged }}</dd><dt>Original allocated</dt><dd>{{ $row->aggregate_assigned }}</dd><dt>Outstanding allocated</dt><dd>{{ $row->aggregate_outstanding }}</dd><dt>Remaining assignable</dt><dd>{{ $row->remaining_assignable }}</dd><dt>Capacity</dt><dd>{{ $row->capacity_status }}</dd>@if (property_exists($row, 'latest_purchase_cost'))<dt>Latest purchase cost</dt><dd>{{ $row->latest_purchase_cost !== null ? 'AED '.number_format((float) $row->latest_purchase_cost, 2) : '—' }}</dd>@endif</dl></details></td>
                                    <td class="px-4 py-3"><div class="flex flex-wrap gap-1">@forelse ($row->platforms as $item)<x-filament::badge color="info" size="sm">{{ $item }}</x-filament::badge>@empty<span class="text-gray-400">All</span>@endforelse</div></td>
                                    <td class="px-4 py-3">@if ($row->warehouse)<span class="line-clamp-2" title="{{ $row->warehouse }}">{{ $row->warehouse }}</span>@else<x-filament::badge color="gray">No balance</x-filament::badge>@endif</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-center text-base font-semibold">{{ $row->employee_usable }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-center">{{ $row->reserved }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-center">@if ($row->is_quantity_limited)<span class="font-semibold">{{ $row->remaining_allocation }}</span><span class="block text-xs text-gray-500">of {{ $row->assigned_quantity }}</span>@else<span class="text-gray-500">Shared</span>@endif</td>
                                    <td class="px-4 py-3">@if ($row->is_quantity_limited && $row->remaining_allocation === 0)<x-filament::badge color="gray">Allocation Exhausted</x-filament::badge>@elseif ($row->stock_status === 'out_of_stock')<x-filament::badge color="danger">Out of Stock</x-filament::badge>@elseif ($row->stock_status === 'low_stock')<x-filament::badge color="warning">Low Stock</x-filament::badge>@else<x-filament::badge color="success">In Stock</x-filament::badge>@endif</td>
                                    <td class="px-4 py-3"><x-filament::badge color="gray" size="sm"><span title="{{ $row->visibility_reasons[0] }}">{{ \Illuminate\Support\Str::limit($row->visibility_reasons[0], 42) }}</span></x-filament::badge>@if (count($row->visibility_reasons) > 1)<details class="mt-2 text-xs"><summary class="cursor-pointer font-medium text-primary-600 dark:text-primary-400">+{{ count($row->visibility_reasons) - 1 }} more</summary><div class="mt-2 flex flex-wrap gap-1">@foreach (array_slice($row->visibility_reasons, 1) as $reason)<x-filament::badge color="gray" size="sm">{{ $reason }}</x-filament::badge>@endforeach</div></details>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="space-y-3 lg:hidden">
                    @foreach ($inventoryRows as $row)
                        <article class="min-w-0 rounded-lg border border-gray-200 p-4 dark:border-white/10">
                            <div class="flex items-start justify-between gap-3"><div class="min-w-0"><p class="line-clamp-2 font-semibold text-gray-950 dark:text-white" title="{{ $row->name }}">{{ $row->name }}</p><p class="mt-1 truncate text-xs text-gray-500">{{ $row->sku }}@if ($row->model) · {{ $row->model }}@endif</p></div>@if ($row->is_quantity_limited && $row->remaining_allocation === 0)<x-filament::badge color="gray">Allocation Exhausted</x-filament::badge>@elseif ($row->stock_status === 'out_of_stock')<x-filament::badge color="danger">Out of Stock</x-filament::badge>@elseif ($row->stock_status === 'low_stock')<x-filament::badge color="warning">Low Stock</x-filament::badge>@else<x-filament::badge color="success">In Stock</x-filament::badge>@endif</div>
                            <div class="mt-3 flex flex-wrap gap-1">@foreach ($row->platforms as $item)<x-filament::badge color="info" size="sm">{{ $item }}</x-filament::badge>@endforeach @if (! $row->warehouse)<x-filament::badge color="gray">No balance</x-filament::badge>@endif</div>
                            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm"><div><dt class="text-xs text-gray-500">Brand</dt><dd>{{ $row->brand ?: '—' }}</dd></div><div><dt class="text-xs text-gray-500">Location</dt><dd>{{ $row->warehouse ?: 'No balance' }}</dd></div><div><dt class="text-xs text-gray-500">Employee usable</dt><dd class="font-semibold">{{ $row->employee_usable }}</dd></div><div><dt class="text-xs text-gray-500">Reserved</dt><dd>{{ $row->reserved }}</dd></div>@if ($row->is_quantity_limited)<div><dt class="text-xs text-gray-500">My remaining</dt><dd>{{ $row->remaining_allocation }} of {{ $row->assigned_quantity }}</dd></div>@endif</dl>
                            <div class="mt-3 flex flex-wrap gap-1">@foreach ($row->visibility_reasons as $reason)<x-filament::badge color="gray" size="sm">{{ $reason }}</x-filament::badge>@endforeach</div>
                            <details class="mt-3 border-t border-gray-100 pt-3 text-xs text-gray-500 dark:border-white/5"><summary class="cursor-pointer font-medium text-primary-600 dark:text-primary-400">More inventory details</summary><p class="mt-2">Available {{ $row->available }} · Damaged {{ $row->damaged }} · Capacity {{ $row->capacity_status }}</p>@if (property_exists($row, 'latest_purchase_cost'))<p class="mt-1">Latest purchase cost: {{ $row->latest_purchase_cost !== null ? 'AED '.number_format((float) $row->latest_purchase_cost, 2) : '—' }}</p>@endif</details>
                        </article>
                    @endforeach
                </div>

                <div class="mt-5 flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:items-center sm:justify-between dark:border-white/5">
                    <div class="flex items-center gap-3 text-sm text-gray-500 dark:text-gray-400">
                        <span>Showing {{ $inventoryRows->firstItem() }}–{{ $inventoryRows->lastItem() }} of {{ $inventoryRows->total() }}</span>
                        <label class="flex items-center gap-2"><span>Per page</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="perPage"><option value="10">10</option><option value="25">25</option><option value="50">50</option></x-filament::input.select></x-filament::input.wrapper></label>
                    </div>
                    <div class="min-w-0">{{ $inventoryRows->onEachSide(1)->links() }}</div>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
