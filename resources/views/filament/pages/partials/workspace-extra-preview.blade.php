@if($extraPreview)
    <section class="tpz-unified-overview-panel" aria-label="{{ $extraPreview['label'] }} records">
        <div class="tpz-native-module-header">
            <span class="tpz-unified-module-icon"><x-filament::icon :icon="$extraPreview['icon']" class="size-6" /></span>
            <div>
                <h2>{{ $extraPreview['label'] }}</h2>
                <p>Authorized records and actions from the existing ERP module.</p>
            </div>
            @if($extraPreview['total'] !== null)
                <div class="tpz-native-module-count">
                    <small>Total Records</small>
                    <strong>{{ number_format($extraPreview['total']) }}</strong>
                </div>
            @endif
        </div>
        @if($extraPreview['total'] !== null)
            <div class="tpz-native-overview-metrics tpz-native-module-totals">
                <div class="tpz-native-overview-metric tpz-native-overview-metric--blue">
                    <span class="tpz-native-overview-icon"><x-filament::icon :icon="$extraPreview['icon']" class="size-5" /></span>
                    <span class="tpz-native-overview-label">{{ $extraPreview['label'] }} Records</span>
                    <strong>{{ number_format($extraPreview['total']) }}</strong>
                </div>
            </div>
        @endif
        @if($extraPreview['records']->isNotEmpty())
            <div class="tpz-native-record-table-wrap">
                <table class="tpz-native-record-table">
                    <thead><tr><th>Reference</th><th>Created</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                    @foreach($extraPreview['records'] as $record)
                        <tr wire:key="extra-preview-{{ $loop->index }}">
                            <td><strong>{{ $record['reference'] }}</strong></td>
                            <td>{{ $record['date'] }}</td>
                            <td>{{ $record['status'] ?? '—' }}</td>
                            <td><a href="{{ $record['url'] }}">View →</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="tpz-native-no-preview">Open the full module to access the permitted records, filters, and actions.</p>
        @endif
        <div class="tpz-native-module-footer">
            <x-filament::button tag="a" :href="$extraPreview['url']" icon="heroicon-o-arrow-up-right">Open full module</x-filament::button>
        </div>
    </section>
@endif
