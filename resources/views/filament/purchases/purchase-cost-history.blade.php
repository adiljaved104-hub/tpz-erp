<div class="space-y-6">
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
            <div class="text-sm text-gray-500 dark:text-gray-400">Product</div>
            <div class="line-clamp-2 max-w-xl font-semibold text-gray-950 dark:text-white" title="{{ $history->productName }}">{{ \Illuminate\Support\Str::limit($history->productName, 90) }}</div>
        </div>
        <div>
            <div class="text-sm text-gray-500 dark:text-gray-400">SKU</div>
            <div class="font-semibold text-gray-950 dark:text-white">{{ $history->sku }}</div>
        </div>
        <div>
            <div class="text-sm text-gray-500 dark:text-gray-400">Latest Received Purchase Cost</div>
            <div class="font-semibold text-gray-950 dark:text-white">
                {{ $history->latestReceivedCost === null ? 'No received cost' : \App\Support\AedMoney::format($history->latestReceivedCost) }}
            </div>
        </div>
        <div>
            <div class="text-sm text-gray-500 dark:text-gray-400">Latest Purchase Date</div>
            <div class="font-semibold text-gray-950 dark:text-white">
                {{ $history->latestPurchaseDate?->format('d M Y') ?? '—' }}
            </div>
        </div>
        <div>
            <div class="text-sm text-gray-500 dark:text-gray-400">Latest Receipt Date</div>
            <div class="font-semibold text-gray-950 dark:text-white">
                {{ app(\App\Services\UserTimezoneResolver::class)->format($history->latestReceiptDate, 'd M Y, h:i A T') ?? '—' }}
            </div>
        </div>
        <div>
            <div class="text-sm text-gray-500 dark:text-gray-400">Latest Received Quantity</div>
            <div class="font-semibold text-gray-950 dark:text-white">{{ $history->latestReceivedQuantity ?? '—' }}</div>
        </div>
        <div>
            <div class="text-sm text-gray-500 dark:text-gray-400">Weighted Average Received Purchase Cost</div>
            <div class="font-semibold text-gray-950 dark:text-white">
                {{ $history->weightedAverageReceivedCost === null ? 'No received cost' : \App\Support\AedMoney::format($history->weightedAverageReceivedCost) }}
            </div>
        </div>
    </div>

    <div>
        <h3 class="mb-3 text-base font-semibold text-gray-950 dark:text-white">Last 5 Posted Goods Receipts (GRNs)</h3>

        <div class="max-w-full overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
            <table class="min-w-[48rem] w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                <thead class="bg-gray-50 dark:bg-white/5">
                    <tr>
                        <th class="whitespace-nowrap px-4 py-3 text-left font-semibold">Purchase Date</th>
                        <th class="whitespace-nowrap px-4 py-3 text-left font-semibold">Goods Receipt Date</th>
                        <th class="whitespace-nowrap px-4 py-3 text-right font-semibold">Quantity Received</th>
                        <th class="whitespace-nowrap px-4 py-3 text-right font-semibold">Unit Cost</th>
                        <th class="whitespace-nowrap px-4 py-3 text-left font-semibold">Purchase</th>
                        <th class="whitespace-nowrap px-4 py-3 text-left font-semibold">GRN</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @forelse ($history->recentEntries as $entry)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3">{{ $entry['purchase_date']->format('d M Y') }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ app(\App\Services\UserTimezoneResolver::class)->format($entry['received_at'], 'd M Y, h:i A T') }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">{{ $entry['quantity'] }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">{{ \App\Support\AedMoney::format($entry['unit_cost']) }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ $entry['purchase_reference'] }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ $entry['grn_reference'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                No posted Purchase receipt costs are available.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
