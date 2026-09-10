<x-filament-panels::page>
    <div class="space-y-6">
        @if ($platformResponsibilities->isNotEmpty())
            <x-filament::section heading="My Platform Responsibilities">
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($platformResponsibilities as $scope)
                        <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
                            <div class="font-semibold">{{ $scope['platform'] }}</div>
                            <div class="text-sm text-gray-500">{{ $scope['reference'] }}</div>
                            @if ($scope['brand']) <div class="text-sm">Brand: {{ $scope['brand'] }}</div> @endif
                            @if ($scope['product']) <div class="text-sm">Product: {{ $scope['product'] }}</div> @endif
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        <x-filament::section heading="My Products">
            @if ($inventoryRows->isEmpty())
                <div class="py-8 text-center text-sm text-gray-500">No active Product responsibilities.</div>
            @else
            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="min-w-[1180px] divide-y divide-gray-200 text-sm dark:divide-white/10">
                    <thead><tr class="text-left">
                        <th class="px-3 py-3">Product</th><th class="px-3 py-3">Brand</th><th class="px-3 py-3">Platform</th><th class="px-3 py-3">Warehouse</th>
                        <th class="px-3 py-3">Available</th><th class="px-3 py-3">Reserved</th><th class="px-3 py-3">Sellable</th><th class="px-3 py-3">Damaged</th>
                        <th class="px-3 py-3">My Qty</th><th class="px-3 py-3">My Remaining</th><th class="px-3 py-3">Original Allocated</th><th class="px-3 py-3">Outstanding Allocated</th><th class="px-3 py-3">Remaining Assignable</th><th class="px-3 py-3">Capacity</th><th class="px-3 py-3">Latest Purchase Cost</th><th class="px-3 py-3">Visible Because</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($inventoryRows as $row)
                            <tr>
                                <td class="max-w-72 px-3 py-3"><div class="line-clamp-2 font-medium" title="{{ $row->name }}">{{ \Illuminate\Support\Str::limit($row->name, 72) }}</div><div class="text-xs font-medium text-gray-500">{{ $row->sku }}</div></td>
                                <td class="px-3 py-3">{{ $row->brand }}</td><td class="px-3 py-3">{{ implode(', ', $row->platforms) ?: '—' }}</td><td class="px-3 py-3">{{ $row->warehouse ?? 'No balance' }}</td>
                                <td class="px-3 py-3">{{ $row->available }}</td><td class="px-3 py-3">{{ $row->reserved }}</td><td class="px-3 py-3">{{ $row->sellable }}</td><td class="px-3 py-3">{{ $row->damaged }}</td>
                                <td class="px-3 py-3">{{ $row->assigned_quantity }}</td><td class="px-3 py-3">{{ $row->remaining_allocation }}</td><td class="px-3 py-3">{{ $row->aggregate_assigned }}</td><td class="px-3 py-3">{{ $row->aggregate_outstanding }}</td><td class="px-3 py-3">{{ $row->remaining_assignable }}</td><td class="px-3 py-3">{{ $row->capacity_status }}</td>
                                <td class="px-3 py-3 whitespace-nowrap">{{ property_exists($row, 'latest_purchase_cost') && $row->latest_purchase_cost !== null ? 'AED '.number_format((float) $row->latest_purchase_cost, 2) : '—' }}</td>
                                <td class="px-3 py-3">{{ implode(', ', $row->visibility_reasons) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
