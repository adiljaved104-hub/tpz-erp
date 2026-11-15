<x-filament-panels::page>
    <div class="tpz-workspace">
        <header class="tpz-workspace-header tpz-workspace-header--plain flex flex-wrap items-start justify-between gap-4">
            <div>
                <nav class="tpz-native-breadcrumb" aria-label="Breadcrumb"><span>Inventory & Warehouse</span><span aria-hidden="true">/</span><span aria-current="page">Inventory Workspace</span></nav>
                <h1 class="tpz-workspace-title">Inventory &amp; Warehouse</h1>
                <p class="tpz-workspace-subtitle">Available Stock, reservations and actions within your authorized responsibility.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if(isset($links['createRequest']))
                    <x-filament::button tag="a" :href="$links['createRequest']" icon="heroicon-o-plus">Create Stock Request</x-filament::button>
                @elseif(isset($links['requests']))
                    <x-filament::button tag="a" :href="$links['requests']" icon="heroicon-o-clipboard-document-list">Stock Requests</x-filament::button>
                @endif
                @if(isset($links['transfers']))
                    <x-filament::button tag="a" color="gray" :href="$links['transfers']" icon="heroicon-o-truck">Stock Transfers</x-filament::button>
                @endif
            </div>
        </header>

        <nav class="tpz-workspace-tabs" aria-label="Inventory workspace sections">
            <button type="button" class="tpz-workspace-tab {{ $activeTab === 'overview' ? 'is-active' : '' }}" aria-selected="{{ $activeTab === 'overview' ? 'true' : 'false' }}" wire:click="setTab('overview')">Overview</button>
            @foreach (['products' => 'Products', 'inventory' => 'Inventory', 'myInventory' => 'My Inventory', 'requests' => 'Requests', 'transfers' => 'Transfers', 'adjustments' => 'Adjustments'] as $key => $label)
                @if(isset($links[$key]))
                    <button type="button" class="tpz-workspace-tab {{ $activeTab === $key ? 'is-active' : '' }}" aria-selected="{{ $activeTab === $key ? 'true' : 'false' }}" wire:click="setTab('{{ $key }}')">{{ $label }}</button>
                @endif
            @endforeach
        </nav>

        @if($canSeeSummary && $activeTab === 'overview')
            <div class="tpz-workspace-metrics tpz-reference-inventory-metrics">
                @foreach ([
                    'products' => ['label' => 'Accessible Products', 'icon' => 'heroicon-o-cube', 'accent' => 'blue'],
                    'available' => ['label' => 'Available Stock', 'icon' => 'heroicon-o-check-circle', 'accent' => 'teal'],
                    'reserved' => ['label' => 'Reserved Stock', 'icon' => 'heroicon-o-clock', 'accent' => 'amber'],
                    'allocated' => ['label' => 'Allocated Stock', 'icon' => 'heroicon-o-squares-2x2', 'accent' => 'violet'],
                ] as $key => $metric)
                    <div class="tpz-workspace-metric tpz-workspace-metric--{{ $metric['accent'] }}">
                        <span class="tpz-workspace-metric-icon"><x-filament::icon :icon="$metric['icon']" class="size-5" /></span>
                        <div class="min-w-0">
                            <p class="tpz-workspace-metric-label">{{ $metric['label'] }}</p>
                            <p class="tpz-workspace-metric-value">{{ number_format($summary[$key]) }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if($canSeeSummary && $activeTab === 'overview')
            <x-filament::section>
                <div class="mb-4">
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">Low Stock</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Authorized products with sellable stock at or below the configured threshold ({{ $lowStockThreshold }} units).</p>
                </div>
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    @forelse($lowStockProducts as $productRow)
                        <div class="flex min-w-0 items-start justify-between gap-3 rounded-xl border border-amber-100 bg-amber-50/60 p-3 dark:border-amber-900/40 dark:bg-amber-950/20">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-gray-950 dark:text-white">{{ $productRow['product']->sku }}</p>
                                <p class="line-clamp-2 text-xs text-gray-600 dark:text-gray-300">{{ $productRow['product']->name }}</p>
                                @if($canViewProducts && \App\Filament\Resources\Products\ProductResource::canView($productRow['product']))
                                    <a class="mt-2 inline-block text-xs font-semibold text-primary-700 hover:underline dark:text-primary-300" href="{{ \App\Filament\Resources\Products\ProductResource::getUrl('view', ['record' => $productRow['product']]) }}">View product →</a>
                                @endif
                            </div>
                            <x-filament::badge :color="$productRow['sellable'] <= 0 ? 'danger' : 'warning'">
                                {{ $productRow['sellable'] <= 0 ? 'Out of stock' : $productRow['sellable'] . ' sellable' }}
                            </x-filament::badge>
                        </div>
                    @empty
                        <p class="py-2 text-sm text-gray-500 dark:text-gray-400">No low-stock products in your authorized view.</p>
                    @endforelse
                </div>
            </x-filament::section>
        @endif

        @if(in_array($activeTab, ['overview', 'inventory'], true))
        <x-filament::section>
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">Authorized Inventory</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Responsibility-scoped stock quantities. Search covers all matching records.</p>
                </div>
                <div class="w-full sm:w-72">
                    <x-filament::input.wrapper>
                        <x-filament::input type="search" wire:model.live.debounce.300ms="search" placeholder="Search by SKU or product" aria-label="Search inventory" />
                    </x-filament::input.wrapper>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="tpz-workspace-table">
                    <thead>
                        <tr><th>SKU &amp; product</th><th>Warehouse</th><th class="text-right">Available</th><th class="text-right">Reserved</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                        @forelse($inventory as $row)
                            <tr wire:key="inventory-{{ $row->id }}">
                                <td>
                                    <div class="font-semibold text-gray-900 dark:text-white">{{ $row->product?->sku }}</div>
                                    <div class="mt-0.5 text-xs text-gray-500">{{ $row->product?->name }}</div>
                                </td>
                                <td>{{ $row->warehouse?->name ?? '—' }}</td>
                                <td class="text-right font-semibold tabular-nums">{{ number_format($row->available_quantity) }}</td>
                                <td class="text-right tabular-nums">{{ number_format($row->reserved_quantity) }}</td>
                                <td>
                                    @if($canViewProducts && $row->product && \App\Filament\Resources\Products\ProductResource::canView($row->product))
                                        <x-filament::button tag="a" size="xs" color="gray" :href="\App\Filament\Resources\Products\ProductResource::getUrl('view', ['record' => $row->product_id])">View product</x-filament::button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-10 text-center text-sm text-gray-500">No inventory matches your search and responsibility scope.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($inventory instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
                <div class="mt-4">{{ $inventory->links() }}</div>
            @endif
        </x-filament::section>
        @endif

        @if(in_array($activeTab, ['products', 'myInventory', 'requests', 'transfers'], true) && isset($links[$activeTab]))
            @php
                $resourceClass = match ($activeTab) {
                    'products' => \App\Filament\Resources\Products\ProductResource::class,
                    'myInventory' => \App\Filament\Resources\ProductInventories\ProductInventoryResource::class,
                    'requests' => \App\Filament\Resources\StockRequests\StockRequestResource::class,
                    'transfers' => \App\Filament\Resources\StockTransfers\StockTransferResource::class,
                };
            @endphp
            <x-filament::section>
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ ['products' => 'Products', 'myInventory' => 'My Inventory', 'requests' => 'Stock Requests', 'transfers' => 'Stock Transfers'][$activeTab] }}</h2>
                    <x-filament::button tag="a" size="sm" color="gray" :href="$links[$activeTab]">Open full module</x-filament::button>
                </div>
                <div class="overflow-x-auto">
                    <table class="tpz-workspace-table">
                        <thead><tr><th>Reference / SKU</th><th>Details</th><th>Last updated</th><th>Action</th></tr></thead>
                        <tbody>
                            @forelse($moduleRecords as $record)
                                <tr wire:key="native-module-{{ $activeTab }}-{{ $record->getKey() }}">
                                    <td class="font-semibold text-gray-950 dark:text-white">
                                        {{ $activeTab === 'products' ? $record->sku : ($activeTab === 'myInventory' ? ($record->product?->sku ?? '—') : ($record->reference ?? '—')) }}
                                    </td>
                                    <td>
                                        @if($activeTab === 'products')
                                            {{ $record->name }}
                                        @elseif($activeTab === 'myInventory')
                                            {{ $record->warehouse?->name ?? 'Warehouse' }} · {{ number_format($record->available_quantity ?? 0) }} available
                                        @else
                                            {{ str($record->status?->value ?? $record->status ?? 'Unknown')->headline() }}
                                        @endif
                                    </td>
                                    <td>{{ $record->updated_at?->format('d M Y') ?? '—' }}</td>
                                    <td>
                                        @if($resourceClass::canView($record))
                                            <x-filament::button tag="a" size="xs" color="gray" :href="$resourceClass::getUrl('view', ['record' => $record])">View</x-filament::button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="py-8 text-center text-gray-500">No records in your authorized scope.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($moduleRecords instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
                    <div class="mt-4">{{ $moduleRecords->links() }}</div>
                @endif
            </x-filament::section>
        @endif

        @if($activeTab === 'adjustments' && isset($links['adjustments']))
            <x-filament::section>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">Stock Adjustments</h2>
                        <p class="mt-1 text-sm text-gray-500">Open the existing controlled adjustment form to make authorized stock changes.</p>
                    </div>
                    <x-filament::button tag="a" :href="$links['adjustments']" icon="heroicon-o-arrow-up-right">Open Stock Adjustments</x-filament::button>
                </div>
            </x-filament::section>
        @endif

        @if($activeTab === 'overview' && isset($links['movements']))
            <x-filament::section>
                <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">Recent Inventory Activity</h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Movements visible within your responsibility and Access Control scope.</p>
                    </div>
                    <a class="text-sm font-semibold text-primary-600 hover:underline dark:text-primary-400" href="{{ $links['movements'] }}">View movement history →</a>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-white/10">
                    @forelse($recentMovements as $movement)
                        <a wire:key="movement-{{ $movement->id }}" href="{{ \App\Filament\Resources\StockMovements\StockMovementResource::getUrl('view', ['record' => $movement]) }}" class="flex flex-wrap items-center justify-between gap-3 py-3 hover:bg-gray-50 dark:hover:bg-white/5">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $movement->product?->sku ?? $movement->reference }}</p>
                                <p class="text-xs text-gray-500">{{ $movement->movement_type?->label() ?? 'Inventory movement' }} · {{ $movement->warehouse?->name ?? 'Warehouse' }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-semibold tabular-nums text-gray-900 dark:text-white">{{ number_format($movement->quantity) }} unit(s)</p>
                                <p class="text-xs text-gray-500">{{ $movement->occurred_at?->format('d M Y, H:i') ?? '—' }}</p>
                            </div>
                        </a>
                    @empty
                        <p class="py-5 text-sm text-gray-500">No recent movements are visible in your authorized scope.</p>
                    @endforelse
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
