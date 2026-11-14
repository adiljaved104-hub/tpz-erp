<x-filament-panels::page>
    <div class="tpz-workspace-stack">
        <x-filament::section>
            <div class="tpz-workspace-heading"><div><div class="tpz-eyebrow">Inventory &amp; Warehouse</div><h1 class="tpz-title">Inventory Workspace</h1><p class="tpz-subtitle">Products, inventory, requests, transfers, and adjustments in your authorized scope.</p></div><x-filament::button tag="a" icon="heroicon-m-plus" :href="$links['requests']">Stock Request</x-filament::button></div>
            <div class="tpz-workspace-tabs">@foreach ([['inventory', 'Overview'], ['products', 'Products'], ['myInventory', 'Inventory'], ['requests', 'Stock Requests'], ['transfers', 'Transfers'], ['adjustments', 'Adjustments']] as [$key, $label])<a href="{{ $links[$key] }}" class="tpz-workspace-tab {{ $key === 'inventory' ? 'is-active' : '' }}">{{ $label }}</a>@endforeach</div>
            <div class="mt-4 max-w-sm"><x-filament::input.wrapper><x-filament::input type="search" wire:model.live.debounce.300ms="search" placeholder="Search products or SKU" /></x-filament::input.wrapper></div>
        </x-filament::section>

        <div class="tpz-kpi-grid">
            @foreach ([['products', 'Total Products', 'heroicon-o-cube', 'blue'], ['available', 'In Stock (Units)', 'heroicon-o-circle-stack', 'green'], ['reserved', 'Reserved', 'heroicon-o-lock-closed', 'amber'], ['allocated', 'Allocated', 'heroicon-o-user-group', 'indigo']] as [$key, $label, $icon, $tone])
                <div class="tpz-kpi-card tone-{{ $tone }}"><div class="tpz-kpi-icon"><x-filament::icon :icon="$icon" class="h-5 w-5" /></div><div><div class="tpz-kpi-label">{{ $label }}</div><div class="tpz-kpi-value">{{ number_format($summary[$key]) }}</div></div></div>
            @endforeach
        </div>

        <div class="tpz-panel-grid">
            <x-filament::section compact heading="Low Stock">
                @forelse ($lowStock as $row)<a class="tpz-list-row" href="{{ $row['url'] ?? $links['inventory'] }}"><span><strong>{{ $row['sku'] }}</strong><small>{{ $row['product'] }}</small></span><b>{{ number_format($row['sellable']) }}</b></a>@empty<div class="tpz-empty">No low-stock products in your authorized scope.</div>@endforelse
                @if ($lowStockThreshold !== null)<div class="tpz-panel-note">Low stock threshold: {{ $lowStockThreshold }}</div>@endif
            </x-filament::section>
            <x-filament::section compact heading="Recent Inventory Activity">
                @forelse ($recentMovements as $movement)<div class="tpz-list-row"><span><strong>{{ $movement->reference ?: $movement->movement_type }}</strong><small>{{ $movement->product?->sku }} · {{ $movement->warehouse?->name }}</small></span><time>{{ optional($movement->occurred_at)->format('d M, H:i') }}</time></div>@empty<div class="tpz-empty">No recent inventory activity is visible.</div>@endforelse
            </x-filament::section>
        </div>

        <x-filament::section compact heading="Authorized Inventory"><div class="overflow-x-auto"><table class="tpz-data-table"><thead><tr><th>Product</th><th>Warehouse</th><th>Available</th><th>Reserved</th><th></th></tr></thead><tbody>@forelse ($inventory as $row)<tr><td><strong>{{ $row->product?->sku }}</strong><small>{{ $row->product?->name }}</small></td><td>{{ $row->warehouse?->name }}</td><td>{{ $row->available_quantity }}</td><td>{{ $row->reserved_quantity }}</td><td><x-filament::button tag="a" size="xs" color="gray" :href="\App\Filament\Resources\Products\ProductResource::getUrl('view', ['record' => $row->product_id])">View</x-filament::button></td></tr>@empty<tr><td colspan="5" class="tpz-empty">No inventory is visible in your authorized responsibility scope.</td></tr>@endforelse</tbody></table></div></x-filament::section>
    </div>
</x-filament-panels::page>
