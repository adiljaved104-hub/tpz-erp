<a href="{{ $card['url'] }}" data-dashboard-card="{{ $card['key'] }}" class="erp-dashboard-card-link" aria-label="Open {{ $card['label'] }}">
    <x-filament::section compact>
        <div class="erp-dashboard-card-content">
            <div class="erp-dashboard-card-top">
                <div>
                    <div class="erp-dashboard-card-value">{{ is_int($card['value']) ? number_format($card['value']) : $card['value'] }}</div>
                    <div class="erp-dashboard-card-label">{{ $card['label'] }}</div>
                </div>
                <x-filament::icon :icon="$icons[$card['key']] ?? 'heroicon-o-chart-bar'" class="erp-dashboard-card-icon" />
            </div>
            <div>
                <div class="erp-dashboard-card-help" title="{{ $card['description'] }}">{{ $card['description'] }}</div>
                <div class="erp-dashboard-card-footer">
                    <div class="erp-dashboard-card-open">Open →</div>
                    @if (in_array($card['color'], ['warning', 'danger', 'success'], true))
                        <x-filament::badge :color="$card['color']">{{ $card['color'] === 'danger' ? 'Action' : ($card['color'] === 'warning' ? 'Monitor' : 'Current') }}</x-filament::badge>
                    @endif
                </div>
            </div>
        </div>
    </x-filament::section>
</a>
