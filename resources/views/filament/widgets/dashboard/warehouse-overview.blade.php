@if (! empty($warehouse_breakdown))
    <div class="tpz-warehouse-overview" aria-label="Inventory by warehouse">
        <div class="tpz-warehouse-overview__head">
            <div>
                <h3>Inventory by Warehouse</h3>
                <p>Authorized quantities per physical location; transit is counted separately.</p>
            </div>
            <a href="{{ $warehouse_breakdown['url'] }}">View inventory →</a>
        </div>
        <div class="tpz-warehouse-overview__totals">
            <div><span>Total Inventory</span><strong>{{ number_format($warehouse_breakdown['total']) }}</strong></div>
            <div><span>At Warehouses</span><strong>{{ number_format($warehouse_breakdown['on_location']) }}</strong></div>
            <div><span>In Transit</span><strong>{{ number_format($warehouse_breakdown['in_transit']) }}</strong></div>
        </div>
        <div class="tpz-warehouse-overview__grid">
            @forelse ($warehouse_breakdown['warehouses'] as $warehouse)
                <div class="tpz-warehouse-overview__item">
                    <div class="tpz-warehouse-overview__item-name" title="{{ $warehouse['name'] }}">{{ $warehouse['name'] }}</div>
                    <strong>{{ number_format($warehouse['units']) }} <small>units</small></strong>
                    <p>{{ number_format($warehouse['products']) }} products</p>
                </div>
            @empty
                <p class="tpz-warehouse-overview__empty">No warehouse inventory is visible within your authorized scope.</p>
            @endforelse
        </div>
    </div>
@endif
