@php
    // The dashboard service has already filtered this card by the user's
    // existing Access Control grants and responsibility scope.
    $key = $card['key'] ?? '';
    $label = $card['label'] ?? 'Metric';
    $value = $card['value'] ?? 0;
    $url = $card['url'] ?? null;
    $icon = $icons[$key] ?? 'heroicon-o-chart-bar';
    $tone = match($key) {
        'inventory_units', 'sellable_inventory', 'marketplace_inventory' => 'blue',
        'inventory_value' => 'violet',
        'low_stock', 'qc_pending', 'attendance_late', 'pending_leave' => 'amber',
        'out_of_stock', 'damaged', 'warning_acknowledgments' => 'rose',
        'returns', 'claims', 'warranty', 'internal_repairs', 'complaints' => 'teal',
        default => 'slate',
    };
    $formatted = is_int($value) || is_float($value)
        ? number_format($value, is_float($value) && floor($value) !== $value ? 2 : 0)
        : $value;
@endphp
@if($url)
    <a href="{{ $url }}" class="tpz-native-mini-metric tpz-native-mini-metric--{{ $tone }}" aria-label="{{ $label }}: {{ $formatted }}">
@else
    <div class="tpz-native-mini-metric tpz-native-mini-metric--{{ $tone }}">
@endif
    <span class="tpz-native-mini-icon"><x-filament::icon :icon="$icon" class="size-5" /></span>
    <span class="tpz-native-mini-label">{{ $label }}</span>
    <strong title="{{ $formatted }}">{{ $formatted }}</strong>
    @if ($key === 'marketplace_inventory' && ! empty($card['warehouse_breakdown']))
        <span class="tpz-dashboard-marketplace-chips">
            @foreach ($card['warehouse_breakdown'] as $warehouse)
                <small title="{{ $warehouse['name'] }}: {{ number_format($warehouse['units']) }} units">{{ $warehouse['name'] }} <b>{{ number_format($warehouse['units']) }}</b></small>
            @endforeach
        </span>
    @endif
    @if($url)<span class="tpz-native-mini-open">View <span aria-hidden="true">→</span></span>@endif
@if($url)
    </a>
@else
    </div>
@endif
