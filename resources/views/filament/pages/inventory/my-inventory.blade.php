<x-filament-panels::page>
    <div class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @include('filament.widgets.dashboard.metric-card', ['metricTitle' => 'My Products', 'metricValue' => $summary['products'], 'metricDescription' => 'Products visible through my responsibilities', 'metricIcon' => 'heroicon-o-cube', 'metricAccent' => 'blue', 'metricShowOpen' => false])
            @include('filament.widgets.dashboard.metric-card', ['metricTitle' => 'Usable Stock', 'metricValue' => $summary['usable'], 'metricDescription' => 'Within my current access and allocations', 'metricIcon' => 'heroicon-o-circle-stack', 'metricAccent' => 'emerald', 'metricShowOpen' => false])
            @include('filament.widgets.dashboard.metric-card', ['metricTitle' => 'Low Stock', 'metricValue' => $summary['low'], 'metricDescription' => '1 employee-usable unit · Click to filter', 'metricIcon' => 'heroicon-o-exclamation-triangle', 'metricAccent' => 'amber', 'metricWireClick' => "applyStockStatus('low_stock')", 'metricActive' => $stockStatus === 'low_stock', 'metricShowOpen' => false])
            @include('filament.widgets.dashboard.metric-card', ['metricTitle' => 'Out of Stock', 'metricValue' => $summary['out'], 'metricDescription' => 'No employee-usable stock · Click to filter', 'metricIcon' => 'heroicon-o-x-circle', 'metricAccent' => 'red', 'metricWireClick' => "applyStockStatus('out_of_stock')", 'metricActive' => $stockStatus === 'out_of_stock', 'metricShowOpen' => false])
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
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <label class="block min-w-0 flex-1">
                        <span class="mb-1.5 block text-sm font-medium">Search Products</span>
                        <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                            <x-filament::input wire:model.live.debounce.250ms="search" type="search" placeholder="Search by SKU, product name or model" />
                        </x-filament::input.wrapper>
                    </label>
                    <x-filament::dropdown placement="bottom-end">
                        <x-slot name="trigger">
                            <x-filament::button color="gray" icon="heroicon-m-view-columns">Columns</x-filament::button>
                        </x-slot>
                        <x-filament::dropdown.list>
                            @foreach ($columnDefinitions as $key => $label)
                                <x-filament::dropdown.list.item wire:click="toggleInventoryColumn('{{ $key }}')" :icon="in_array($key, $visibleColumns, true) ? 'heroicon-m-check' : 'heroicon-m-minus'">
                                    {{ $label }}
                                </x-filament::dropdown.list.item>
                            @endforeach
                            <x-filament::dropdown.list.item wire:click="resetInventoryColumns" icon="heroicon-m-arrow-path">Reset to Default</x-filament::dropdown.list.item>
                        </x-filament::dropdown.list>
                    </x-filament::dropdown>
                </div>
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <label class="min-w-0"><span class="mb-1.5 block text-sm font-medium">Brand</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="brand"><option value="">All Brands</option>@foreach ($filterOptions['brands'] as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                    <label class="min-w-0"><span class="mb-1.5 block text-sm font-medium">Category</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="category"><option value="">All Categories</option>@foreach ($filterOptions['categories'] as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                    <label class="min-w-0"><span class="mb-1.5 block text-sm font-medium">Condition</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="condition"><option value="">All Conditions</option>@foreach ($filterOptions['conditions'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
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
                <div class="hidden max-w-full overflow-x-auto rounded-xl border border-gray-200 lg:block dark:border-white/10">
                    <table class="w-full min-w-max divide-y divide-gray-200 text-sm dark:divide-white/10">
                        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:bg-white/5 dark:text-gray-300">
                            <tr>
                                @if (in_array('product', $visibleColumns, true))<th class="min-w-96 px-4 py-3">Product</th>@endif
                                @if (in_array('brand', $visibleColumns, true))<th class="min-w-32 px-4 py-3">Brand</th>@endif
                                @if (in_array('condition', $visibleColumns, true))<th class="min-w-28 px-4 py-3">Condition</th>@endif
                                @if (in_array('platform', $visibleColumns, true))<th class="min-w-40 px-4 py-3">Platform</th>@endif
                                @if (in_array('location', $visibleColumns, true))<th class="min-w-40 px-4 py-3">Location</th>@endif
                                @if (in_array('physical_sellable', $visibleColumns, true))<th class="min-w-28 px-4 py-3 text-center">Physical Sellable</th>@endif
                                @if (in_array('reserved', $visibleColumns, true))<th class="min-w-24 px-4 py-3 text-center">Reserved</th>@endif
                                @if (in_array('allocation_remaining', $visibleColumns, true))<th class="min-w-32 px-4 py-3 text-center">Allocation Remaining</th>@endif
                                @if (in_array('usable_now', $visibleColumns, true))<th class="min-w-24 px-4 py-3 text-center">Usable Now</th>@endif
                                @if (in_array('status', $visibleColumns, true))<th class="min-w-36 px-4 py-3">Status</th>@endif
                                @if (in_array('visible_because', $visibleColumns, true))<th class="min-w-64 px-4 py-3">Visible Because</th>@endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($inventoryRows as $row)
                                <tr class="align-top">
                                    @if (in_array('product', $visibleColumns, true))
                                        <td class="min-w-96 max-w-xl px-4 py-3"><p class="whitespace-normal break-words font-medium leading-5 text-gray-950 dark:text-white">{{ $row->name }}</p><p class="mt-1 whitespace-normal break-words text-xs font-medium text-gray-500">{{ $row->sku }}@if ($row->model) · {{ $row->model }}@endif</p><details class="mt-2 text-xs text-gray-500"><summary class="cursor-pointer font-medium text-primary-600 dark:text-primary-400">Inventory details</summary><dl class="mt-2 grid min-w-72 grid-cols-2 gap-x-4 gap-y-1"><dt>Available</dt><dd>{{ $row->available }}</dd><dt>Damaged</dt><dd>{{ $row->damaged }}</dd><dt>Original allocated</dt><dd>{{ $row->aggregate_assigned }}</dd><dt>Outstanding allocated</dt><dd>{{ $row->aggregate_outstanding }}</dd><dt>Remaining assignable</dt><dd>{{ $row->remaining_assignable }}</dd><dt>Capacity</dt><dd>{{ $row->capacity_status }}</dd>@if (property_exists($row, 'latest_purchase_cost'))<dt>Latest purchase cost</dt><dd>{{ $row->latest_purchase_cost !== null ? 'AED '.number_format((float) $row->latest_purchase_cost, 2) : '—' }}</dd>@endif</dl></details></td>
                                    @endif
                                    @if (in_array('brand', $visibleColumns, true))<td class="px-4 py-3">{{ $row->brand ?: '—' }}</td>@endif
                                    @if (in_array('condition', $visibleColumns, true))<td class="px-4 py-3"><x-filament::badge color="gray">{{ $row->condition_label }}</x-filament::badge></td>@endif
                                    @if (in_array('platform', $visibleColumns, true))<td class="px-4 py-3"><div class="flex min-w-32 flex-wrap gap-1">@forelse ($row->platforms as $item)<x-filament::badge color="info" size="sm"><span class="whitespace-normal">{{ $item }}</span></x-filament::badge>@empty<span class="text-gray-400">All</span>@endforelse</div></td>@endif
                                    @if (in_array('location', $visibleColumns, true))<td class="px-4 py-3">@if ($row->warehouse)<span class="whitespace-normal break-words">{{ $row->warehouse }}</span>@else<x-filament::badge color="gray">No balance</x-filament::badge>@endif</td>@endif
                                    @if (in_array('physical_sellable', $visibleColumns, true))<td class="whitespace-nowrap px-4 py-3 text-center">{{ $row->sellable }}</td>@endif
                                    @if (in_array('reserved', $visibleColumns, true))<td class="whitespace-nowrap px-4 py-3 text-center">{{ $row->reserved }}</td>@endif
                                    @if (in_array('allocation_remaining', $visibleColumns, true))<td class="whitespace-nowrap px-4 py-3 text-center">@if ($row->is_quantity_limited)<span class="font-semibold">{{ $row->remaining_allocation }}</span><span class="block text-xs text-gray-500">of {{ $row->assigned_quantity }}</span>@else<span class="text-gray-500">Shared</span>@endif</td>@endif
                                    @if (in_array('usable_now', $visibleColumns, true))<td class="whitespace-nowrap px-4 py-3 text-center text-base font-semibold">{{ $row->employee_usable }}</td>@endif
                                    @if (in_array('status', $visibleColumns, true))<td class="px-4 py-3">@if ($row->is_quantity_limited && $row->remaining_allocation === 0)<x-filament::badge color="gray">Allocation Exhausted</x-filament::badge>@elseif ($row->stock_status === 'out_of_stock')<x-filament::badge color="danger">Out of Stock</x-filament::badge>@elseif ($row->stock_status === 'low_stock')<x-filament::badge color="warning">Low Stock</x-filament::badge>@else<x-filament::badge color="success">In Stock</x-filament::badge>@endif</td>@endif
                                    @if (in_array('visible_because', $visibleColumns, true))<td class="min-w-64 px-4 py-3"><div class="flex flex-wrap gap-1">@foreach ($row->visibility_reasons as $reason)<x-filament::badge color="gray" size="sm"><span class="whitespace-normal break-words">{{ $reason }}</span></x-filament::badge>@endforeach</div></td>@endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="space-y-3 lg:hidden">
                    @foreach ($inventoryRows as $row)
                        <article class="min-w-0 rounded-lg border border-gray-200 p-4 dark:border-white/10">
                            <div class="flex items-start justify-between gap-3">@if (in_array('product', $visibleColumns, true))<div class="min-w-0 flex-1"><p class="whitespace-normal break-words font-semibold text-gray-950 dark:text-white">{{ $row->name }}</p><p class="mt-1 whitespace-normal break-words text-xs text-gray-500">{{ $row->sku }}@if ($row->model) · {{ $row->model }}@endif</p></div>@endif @if (in_array('status', $visibleColumns, true))<div class="shrink-0">@if ($row->is_quantity_limited && $row->remaining_allocation === 0)<x-filament::badge color="gray">Allocation Exhausted</x-filament::badge>@elseif ($row->stock_status === 'out_of_stock')<x-filament::badge color="danger">Out of Stock</x-filament::badge>@elseif ($row->stock_status === 'low_stock')<x-filament::badge color="warning">Low Stock</x-filament::badge>@else<x-filament::badge color="success">In Stock</x-filament::badge>@endif</div>@endif</div>
                            @if (in_array('platform', $visibleColumns, true) || in_array('condition', $visibleColumns, true))<div class="mt-3 flex flex-wrap gap-1">@if (in_array('condition', $visibleColumns, true))<x-filament::badge color="gray" size="sm">{{ $row->condition_label }}</x-filament::badge>@endif @if (in_array('platform', $visibleColumns, true))@foreach ($row->platforms as $item)<x-filament::badge color="info" size="sm"><span class="whitespace-normal">{{ $item }}</span></x-filament::badge>@endforeach @endif</div>@endif
                            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">@if (in_array('brand', $visibleColumns, true))<div><dt class="text-xs text-gray-500">Brand</dt><dd>{{ $row->brand ?: '—' }}</dd></div>@endif @if (in_array('location', $visibleColumns, true))<div><dt class="text-xs text-gray-500">Location</dt><dd class="break-words">{{ $row->warehouse ?: 'No balance' }}</dd></div>@endif @if (in_array('physical_sellable', $visibleColumns, true))<div><dt class="text-xs text-gray-500">Physical Sellable</dt><dd>{{ $row->sellable }}</dd></div>@endif @if (in_array('allocation_remaining', $visibleColumns, true))<div><dt class="text-xs text-gray-500">Allocation Remaining</dt><dd>{{ $row->is_quantity_limited ? $row->remaining_allocation.' of '.$row->assigned_quantity : 'Shared' }}</dd></div>@endif @if (in_array('usable_now', $visibleColumns, true))<div><dt class="text-xs text-gray-500">Usable Now</dt><dd class="font-semibold">{{ $row->employee_usable }}</dd></div>@endif @if (in_array('reserved', $visibleColumns, true))<div><dt class="text-xs text-gray-500">Reserved</dt><dd>{{ $row->reserved }}</dd></div>@endif</dl>
                            @if (in_array('visible_because', $visibleColumns, true))<div class="mt-3 flex flex-wrap gap-1">@foreach ($row->visibility_reasons as $reason)<x-filament::badge color="gray" size="sm"><span class="whitespace-normal break-words">{{ $reason }}</span></x-filament::badge>@endforeach</div>@endif
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
