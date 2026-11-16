@if (! empty($warehouse_breakdown))
    @foreach ($warehouse_breakdown['warehouses'] as $warehouse)
        <a href="{{ $warehouse_breakdown['url'] }}" class="tpz-native-mini-metric tpz-dashboard-warehouse-tile" aria-label="{{ $warehouse['name'] }} stock: {{ number_format($warehouse['units']) }} units">
            <span class="tpz-native-mini-icon"><x-filament::icon icon="heroicon-o-building-office-2" class="size-5" /></span>
            <span class="tpz-native-mini-label" title="{{ $warehouse['name'] }}">{{ $warehouse['name'] }}</span>
            <strong>{{ number_format($warehouse['units']) }}</strong>
            <span class="tpz-native-mini-open">View <span aria-hidden="true">→</span></span>
        </a>
    @endforeach
    @if ($warehouse_breakdown['in_transit'] > 0)
        <div class="tpz-native-mini-metric tpz-native-mini-metric--amber tpz-dashboard-warehouse-tile">
            <span class="tpz-native-mini-icon"><x-filament::icon icon="heroicon-o-truck" class="size-5" /></span>
            <span class="tpz-native-mini-label">In Transit</span>
            <strong>{{ number_format($warehouse_breakdown['in_transit']) }}</strong>
        </div>
    @endif
@endif
