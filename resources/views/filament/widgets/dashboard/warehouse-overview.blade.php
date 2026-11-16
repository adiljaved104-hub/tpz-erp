@if (! empty($warehouse_breakdown))
    <div class="tpz-warehouse-inline" aria-label="Warehouse quantities">
        <div class="tpz-warehouse-inline__heading">
            <span>Warehouse Stock</span>
            <span class="tpz-warehouse-inline__detail">Authorized units by location</span>
            <a href="{{ $warehouse_breakdown['url'] }}">View →</a>
        </div>
        <div class="tpz-warehouse-inline__grid">
            @forelse ($warehouse_breakdown['warehouses'] as $warehouse)
                <div class="tpz-warehouse-inline__card">
                    <div class="tpz-warehouse-inline__name" title="{{ $warehouse['name'] }}">{{ $warehouse['name'] }}</div>
                    <div class="tpz-warehouse-inline__value">{{ number_format($warehouse['units']) }} <small>units</small></div>
                    <div class="tpz-warehouse-inline__products">{{ number_format($warehouse['products']) }} products</div>
                </div>
            @empty
                <p class="tpz-warehouse-inline__empty">No warehouse stock in your authorized scope.</p>
            @endforelse
            @if ($warehouse_breakdown['in_transit'] > 0)
                <div class="tpz-warehouse-inline__card tpz-warehouse-inline__card--transit">
                    <div class="tpz-warehouse-inline__name">In Transit</div>
                    <div class="tpz-warehouse-inline__value">{{ number_format($warehouse_breakdown['in_transit']) }} <small>units</small></div>
                    <div class="tpz-warehouse-inline__products">Not double-counted in warehouses</div>
                </div>
            @endif
        </div>
    </div>
@endif
