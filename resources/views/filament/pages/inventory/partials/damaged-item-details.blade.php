<dl class="grid gap-4 text-sm sm:grid-cols-2">
    @foreach ([
        'Product' => collect([$item['sku'] ?? null, $item['product_name'] ?? null])->filter()->join(' — '),
        'Remaining Quantity' => $item['quantity'] ?? 'Not recorded',
        'Location' => $item['location'] ?? 'Not recorded',
        'Source' => $item['source_label'] ?? 'Not recorded',
        'Platform' => $item['platform'] ?? 'Not recorded',
        'Status' => $item['status_label'] ?? 'Not recorded',
        'Date' => $item['damaged_date'] ?? 'Not recorded',
        'Days Damaged' => $item['days_damaged'] ?? 'Not recorded',
        'Reference' => $item['related_reference'] ?? 'Not recorded',
        'Handled by' => $item['reported_by'] ?? 'Not recorded',
    ] as $label => $value)
        <div class="min-w-0">
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</dt>
            <dd class="mt-1 break-words font-medium text-gray-950 dark:text-white">{{ filled($value) ? $value : 'Not recorded' }}</dd>
        </div>
    @endforeach

    <div class="min-w-0 sm:col-span-2">
        <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Reason</dt>
        <dd class="mt-1 whitespace-pre-wrap break-words text-gray-950 dark:text-white">{{ $item['reason'] ?? 'Not recorded' }}</dd>
    </div>
</dl>
