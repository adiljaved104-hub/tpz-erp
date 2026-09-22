<div class="space-y-4">
    @foreach ($amendments as $amendment)
        <x-filament::section :heading="$amendment->created_at->format('d M Y, h:i A')">
            <p class="text-sm">By {{ $amendment->amendedBy?->name ?? 'Former user' }} · {{ $amendment->after_window_override ? 'After-window override' : 'Within normal window' }}</p>
            <p class="text-sm">Reason: {{ $amendment->reason }}</p>
            <div class="space-y-2 text-sm">
                @foreach ($amendment->lines as $line)
                    <div class="break-words">
                        <span class="font-medium">{{ $line->order_item_id ? 'Line #'.$line->order_item_id.' · ' : '' }}{{ str_replace('_', ' ', ucfirst($line->field)) }}:</span>
                        @if ($line->field === 'warehouse_correction')
                            {{ json_decode($line->old_value, true)['name'] ?? 'Unknown warehouse' }} → {{ json_decode($line->new_value, true)['name'] ?? 'Unknown warehouse' }}
                            <span class="text-xs text-gray-500">(documentary correction; original shipment unchanged)</span>
                        @else
                            {{ $line->old_value ?? '—' }} → {{ $line->new_value ?? '—' }}
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endforeach
</div>
