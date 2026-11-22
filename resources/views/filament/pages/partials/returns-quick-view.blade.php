{{-- Phase 7B: read-only, server-authorized preview. Mutating actions remain on the original guarded resource page. --}}
<div class="space-y-4">
    <div class="grid gap-3 sm:grid-cols-2">
        <div>
            <p class="text-xs text-gray-500 dark:text-gray-400">Reference</p>
            <p class="font-semibold text-gray-950 dark:text-white">{{ $record->reference ?? '—' }}</p>
        </div>
        <div>
            <p class="text-xs text-gray-500 dark:text-gray-400">Status</p>
            <p class="font-semibold text-gray-950 dark:text-white">{{ $record->status?->label() ?? '—' }}</p>
        </div>
        <div>
            <p class="text-xs text-gray-500 dark:text-gray-400">Marketplace</p>
            <p class="text-sm text-gray-900 dark:text-gray-100">{{ $record->platform?->name ?? '—' }}</p>
        </div>
        <div>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $isCustomer ? 'Receiving warehouse' : 'Destination warehouse' }}</p>
            <p class="text-sm text-gray-900 dark:text-gray-100">{{ $isCustomer ? ($record->receivingWarehouse?->name ?? '—') : ($record->destinationWarehouse?->name ?? '—') }}</p>
        </div>
        @unless($isCustomer)
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">Source marketplace location</p>
                <p class="text-sm text-gray-900 dark:text-gray-100">{{ $record->sourceWarehouse?->name ?? '—' }}</p>
            </div>
        @endunless
    </div>
    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-gray-50 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                <tr>
                    <th class="px-3 py-2">Product / SKU</th>
                    <th class="px-3 py-2 text-right">Quantity</th>
                    @unless($isCustomer)
                        <th class="px-3 py-2 text-right">Dispatched</th>
                        <th class="px-3 py-2 text-right">Received</th>
                    @endunless
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($record->displayItems as $item)
                    <tr>
                        <td class="px-3 py-2">
                            <div class="font-semibold whitespace-normal break-words text-gray-950 dark:text-white">{{ $isCustomer ? ($item->product_name_snapshot ?? '—') : ($item->product?->name ?? '—') }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $isCustomer ? ($item->sku_snapshot ?? '—') : ($item->product?->sku ?? '—') }}</div>
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ number_format($isCustomer ? $item->return_quantity : $item->quantity) }}</td>
                        @unless($isCustomer)
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format($item->dispatched_quantity) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format($item->received_quantity) }}</td>
                        @endunless
                    </tr>
                @empty
                    <tr><td class="px-3 py-4 text-gray-500" colspan="{{ $isCustomer ? 2 : 4 }}">No authorized line items to display.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-xs text-gray-500 dark:text-gray-400">For processing, use the existing secured record actions.</p>
        <x-filament::button tag="a" :href="$fullUrl" size="sm">{{ $actionLabel }}</x-filament::button>
    </div>
</div>
