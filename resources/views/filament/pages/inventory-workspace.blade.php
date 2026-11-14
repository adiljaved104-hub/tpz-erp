<x-filament-panels::page>
    <div class="tpz-workspace">
        <header class="tpz-workspace-header flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="tpz-workspace-eyebrow">Inventory operations</p>
                <h1 class="tpz-workspace-title">Inventory &amp; Warehouse</h1>
                <p class="tpz-workspace-subtitle">Available stock, reservations and actions within your authorized responsibility.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if(isset($links['requests']))
                    <x-filament::button tag="a" :href="$links['requests']" icon="heroicon-o-clipboard-document-list">Stock Requests</x-filament::button>
                @endif
                @if(isset($links['transfers']))
                    <x-filament::button tag="a" color="gray" :href="$links['transfers']" icon="heroicon-o-truck">Stock Transfers</x-filament::button>
                @endif
            </div>
        </header>

        <nav class="tpz-workspace-tabs" aria-label="Inventory workspace sections">
            <span class="tpz-workspace-tab is-active" aria-current="page">Overview</span>
            @foreach (['products' => 'Products', 'inventory' => 'Inventory', 'myInventory' => 'My Inventory', 'requests' => 'Requests', 'transfers' => 'Transfers', 'adjustments' => 'Adjustments'] as $key => $label)
                @if(isset($links[$key]))
                    <a class="tpz-workspace-tab" href="{{ $links[$key] }}">{{ $label }}</a>
                @endif
            @endforeach
        </nav>

        @if($canSeeSummary)
            <div class="tpz-workspace-metrics">
                @foreach ([
                    'products' => ['label' => 'Accessible products', 'icon' => 'heroicon-o-cube', 'accent' => 'blue'],
                    'available' => ['label' => 'Available stock', 'icon' => 'heroicon-o-check-circle', 'accent' => 'teal'],
                    'reserved' => ['label' => 'Reserved stock', 'icon' => 'heroicon-o-clock', 'accent' => 'amber'],
                    'allocated' => ['label' => 'Owned / allocated view', 'icon' => 'heroicon-o-squares-2x2', 'accent' => 'violet'],
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

        @if($canSeeSummary)
            <x-filament::section>
                <div class="mb-4">
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">Low-stock alerts</h2>
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

        <x-filament::section>
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">Authorized inventory</h2>
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
        @if(isset($links['movements']))
            <x-filament::section>
                <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">Recent inventory activity</h2>
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
