<x-filament::section compact data-dashboard-section="attention">
    <x-slot name="heading">Needs Attention</x-slot>
    <x-slot name="description">Priority operational items in your authorized scope.</x-slot>
    @if ($attention->isEmpty())
        <div class="erp-dashboard-attention-row" data-attention-empty>
            <div class="erp-dashboard-attention-copy">
                <div class="erp-dashboard-attention-label">No items need your attention.</div>
                <div class="erp-dashboard-attention-help">You are caught up with the currently visible priority work.</div>
            </div>
            <x-filament::icon icon="heroicon-o-check-circle" class="erp-dashboard-status-icon" />
        </div>
    @else
        <div class="erp-dashboard-attention-grid">
            @foreach ($attention as $item)
                <x-filament::section compact wire:key="attention-{{ md5($item['label'].$item['url']) }}">
                    <div class="erp-dashboard-attention-row">
                        <div class="erp-dashboard-attention-copy">
                            <div class="erp-dashboard-attention-label" title="{{ $item['label'] }}">{{ $item['label'] }}</div>
                            <div class="erp-dashboard-attention-help">Open the related workflow to take action.</div>
                        </div>
                        <div class="erp-dashboard-attention-action">
                            <x-filament::badge color="warning">{{ number_format($item['count']) }}</x-filament::badge>
                            <x-filament::button tag="a" size="xs" color="gray" outlined :href="$item['url']">View</x-filament::button>
                        </div>
                    </div>
                </x-filament::section>
            @endforeach
        </div>
    @endif
</x-filament::section>
