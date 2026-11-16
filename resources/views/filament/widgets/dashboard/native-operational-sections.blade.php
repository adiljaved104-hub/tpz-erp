{{-- Extra operational information reuses the Classic Dashboard's already
     authorized cards and alerts. No independent unrestricted queries. --}}
@php
    $nativeGroups = [
        'inventory' => [
            'title' => 'Inventory & Warehouse',
            'description' => 'Stock position, valuation and operational inventory status.',
            'icon' => 'heroicon-o-cube',
            'cards' => ['inventory_units', 'sellable_inventory', 'inventory_value', 'low_stock', 'out_of_stock', 'damaged', 'qc_pending', 'marketplace_inventory'],
        ],
        'service' => [
            'title' => 'Service & Claims',
            'description' => 'Operational cases requiring follow-up.',
            'icon' => 'heroicon-o-wrench-screwdriver',
            'cards' => ['returns', 'claims', 'warranty', 'internal_repairs', 'complaints'],
        ],
        'hr' => [
            'title' => 'HR Overview',
            'description' => 'Attendance, leave and acknowledgment status.',
            'icon' => 'heroicon-o-user-group',
            'cards' => ['attendance_present', 'attendance_late', 'attendance_absent', 'pending_leave', 'warning_acknowledgments', 'notice_acknowledgments'],
        ],
    ];
    $nativeVisible = array_flip($visible_widget_keys);
    $nativeCards = [];
    foreach ($nativeGroups as $group => $definition) {
        $nativeCards[$group] = collect($definition['cards'])
            ->map(fn (string $key) => $cardsByKey->get($key))
            ->filter()
            ->values();
    }
    $nativeActionList = isset($nativeVisible['attention']) ? $attention : collect();
@endphp

<div class="tpz-native-operations" aria-label="Operational Dashboard">
    <div class="tpz-native-operational-columns">
        @if(isset($nativeVisible['attention']) && $nativeActionList->isNotEmpty())
            <section class="tpz-native-detail-panel" x-data="{
                    open: localStorage.getItem('tpz-native-section-attention') !== 'closed',
                    toggle() { this.open = !this.open; localStorage.setItem('tpz-native-section-attention', this.open ? 'open' : 'closed') }
                }" aria-label="Needs Attention">
                <button class="tpz-native-detail-header" type="button" x-on:click="toggle()" :aria-expanded="open.toString()" aria-controls="tpz-native-detail-attention">
                    <span class="tpz-native-detail-header-icon attention"><x-filament::icon icon="heroicon-o-exclamation-triangle" class="size-5" /></span>
                    <span class="tpz-native-detail-header-copy"><strong>Needs Attention</strong><small>Priority operational items in your authorized scope.</small></span>
                    <x-filament::icon icon="heroicon-o-chevron-down" class="tpz-native-detail-chevron size-4" x-bind:class="{'is-collapsed': !open}" />
                </button>
                <div id="tpz-native-detail-attention" x-show="open" class="tpz-native-detail-body">
                    <div class="tpz-native-attention-list">
                        @foreach($nativeActionList as $item)
                            @php
                                $attentionLabel = strtolower((string) $item['label']);
                                $attentionIcon = match (true) {
                                    str_contains($attentionLabel, 'claim') => 'heroicon-o-shield-check',
                                    str_contains($attentionLabel, 'qc') => 'heroicon-o-magnifying-glass',
                                    str_contains($attentionLabel, 'damaged') => 'heroicon-o-exclamation-triangle',
                                    str_contains($attentionLabel, 'return') => 'heroicon-o-arrow-uturn-left',
                                    str_contains($attentionLabel, 'purchase') => 'heroicon-o-truck',
                                    str_contains($attentionLabel, 'repair') => 'heroicon-o-wrench-screwdriver',
                                    str_contains($attentionLabel, 'complaint') => 'heroicon-o-megaphone',
                                    str_contains($attentionLabel, 'task') => 'heroicon-o-clipboard-document-check',
                                    default => 'heroicon-o-exclamation-circle',
                                };
                            @endphp
                            <a href="{{ $item['url'] }}" class="tpz-native-attention-link" aria-label="{{ $item['label'] }}: {{ number_format($item['count']) }}. View">
                                <x-filament::icon :icon="$attentionIcon" class="tpz-native-attention-icon size-4" />
                                <span title="{{ $item['label'] }}">{{ $item['label'] }}</span>
                                <b>{{ number_format($item['count']) }}</b>
                                <small aria-hidden="true">View</small>
                            </a>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif
        @if(isset($nativeVisible['service']) && $nativeCards['service']->isNotEmpty())
            <section class="tpz-native-detail-panel" x-data="{
                    open: localStorage.getItem('tpz-native-section-service') !== 'closed',
                    toggle() { this.open = !this.open; localStorage.setItem('tpz-native-section-service', this.open ? 'open' : 'closed') }
                }" aria-label="Service and Claims">
                <button class="tpz-native-detail-header" type="button" x-on:click="toggle()" :aria-expanded="open.toString()" aria-controls="tpz-native-detail-service">
                    <span class="tpz-native-detail-header-icon"><x-filament::icon icon="heroicon-o-wrench-screwdriver" class="size-5" /></span>
                    <span class="tpz-native-detail-header-copy"><strong>Service &amp; Claims</strong><small>Operational cases requiring follow-up.</small></span>
                    <x-filament::icon icon="heroicon-o-chevron-down" class="tpz-native-detail-chevron size-4" x-bind:class="{'is-collapsed': !open}" />
                </button>
                <div id="tpz-native-detail-service" x-show="open" class="tpz-native-detail-body">
                    <div class="tpz-native-detail-card-grid">
                        @foreach($nativeCards['service'] as $card)
                            @include('filament.widgets.dashboard.native-metric', ['card' => $card, 'icons' => $icons])
                        @endforeach
                    </div>
                </div>
            </section>
        @endif
    </div>

    @if(isset($nativeVisible['hr']) && $nativeCards['hr']->isNotEmpty())
        <section class="tpz-native-detail-panel" x-data="{
                open: localStorage.getItem('tpz-native-section-hr') !== 'closed',
                toggle() { this.open = !this.open; localStorage.setItem('tpz-native-section-hr', this.open ? 'open' : 'closed') }
            }" aria-label="HR Overview">
            <button class="tpz-native-detail-header" type="button" x-on:click="toggle()" :aria-expanded="open.toString()" aria-controls="tpz-native-detail-hr">
                <span class="tpz-native-detail-header-icon"><x-filament::icon icon="heroicon-o-user-group" class="size-5" /></span>
                <span class="tpz-native-detail-header-copy"><strong>HR Overview</strong><small>Attendance, leave and acknowledgment status.</small></span>
                <x-filament::icon icon="heroicon-o-chevron-down" class="tpz-native-detail-chevron size-4" x-bind:class="{'is-collapsed': !open}" />
            </button>
            <div id="tpz-native-detail-hr" x-show="open" class="tpz-native-detail-body">
                <div class="tpz-native-detail-card-grid">
                    @foreach($nativeCards['hr'] as $card)
                        @include('filament.widgets.dashboard.native-metric', ['card' => $card, 'icons' => $icons])
                    @endforeach
                </div>
            </div>
        </section>
    @endif
</div>
