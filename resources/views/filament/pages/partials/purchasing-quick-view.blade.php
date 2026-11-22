{{-- Read-only purchase preview: no costs, financial totals or write operations. --}}
<div class="space-y-4">
    <div class="grid gap-3 sm:grid-cols-2">
        <div><p class="text-xs text-gray-500 dark:text-gray-400">Purchase Order</p><p class="font-semibold text-gray-950 dark:text-white">{{ $purchase->reference }}</p></div>
        <div><p class="text-xs text-gray-500 dark:text-gray-400">Status</p><p class="font-semibold text-gray-950 dark:text-white">{{ $purchase->status?->getLabel() ?? '—' }}</p></div>
        <div><p class="text-xs text-gray-500 dark:text-gray-400">Supplier</p><p class="text-sm text-gray-900 dark:text-gray-100">{{ $purchase->supplier?->name ?? '—' }}</p></div>
        <div><p class="text-xs text-gray-500 dark:text-gray-400">Receiving Warehouse</p><p class="text-sm text-gray-900 dark:text-gray-100">{{ $purchase->warehouse?->name ?? '—' }}</p></div>
        <div><p class="text-xs text-gray-500 dark:text-gray-400">Purchase Date</p><p class="text-sm text-gray-900 dark:text-gray-100">{{ $purchase->purchase_date?->format('d M Y') ?? '—' }}</p></div>
        <div><p class="text-xs text-gray-500 dark:text-gray-400">Expected Delivery</p><p class="text-sm text-gray-900 dark:text-gray-100">{{ $purchase->expected_delivery_date?->format('d M Y') ?? '—' }}</p></div>
    </div>
    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-gray-50 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                <tr><th class="px-3 py-2">Product / SKU</th><th class="px-3 py-2 text-right">Ordered</th><th class="px-3 py-2 text-right">Received</th><th class="px-3 py-2 text-right">Remaining</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($purchase->items as $item)
                    <tr>
                        <td class="px-3 py-2">
                            <div class="font-semibold whitespace-normal break-words text-gray-950 dark:text-white">{{ $item->product?->name ?? '—' }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $item->product?->sku ?? '—' }}</div>
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ number_format($item->ordered_quantity) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ number_format($item->received_quantity) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ number_format(max(0, $item->ordered_quantity - $item->received_quantity)) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-3 py-4 text-gray-500">No line items to display.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-xs text-gray-500 dark:text-gray-400">Approval and receiving remain in the existing secured Purchase workflow.</p>
        <x-filament::button tag="a" :href="$fullUrl" size="sm">{{ $actionLabel }}</x-filament::button>
    </div>
</div>
