<x-filament-panels::page>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['Listings monitored', $listings_monitored, 'primary'],
            ['Featured Offer held', $featured_offer_held, 'success'],
            ['Featured Offer lost', $featured_offer_lost, 'danger'],
            ['Active stock exposure', $active_stock_exposure, 'warning'],
            ['Regained (24h)', $featured_offer_regained, 'success'],
            ['Unacknowledged', $unacknowledged, 'warning'],
            ['Escalated', $escalated, 'danger'],
            ['Source failures', $source_failures, 'gray'],
        ] as [$label, $value, $color])
            <x-filament::section compact>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</p>
                <p class="mt-1 text-2xl font-semibold">{{ number_format($value) }}</p>
                <x-filament::badge :color="$color" class="mt-2 w-fit">{{ $value > 0 ? 'Requires review' : 'Clear' }}</x-filament::badge>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section heading="Operational status" description="Unknown source state is never treated as Featured Offer lost.">
        <p class="text-sm">Latest successful check: {{ $latest_successful_check ? \Illuminate\Support\Carbon::parse($latest_successful_check)->format('d M Y, h:i A') : 'No successful observations yet' }}</p>
    </x-filament::section>

    <x-filament::section heading="Active incidents" description="Current incidents grouped by Product, Brand, Platform and responsible Employee/Team.">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[760px] text-sm">
                <thead><tr class="border-b text-left"><th class="p-3">Status</th><th class="p-3">Product</th><th class="p-3">Brand</th><th class="p-3">Platform</th><th class="p-3">Responsible</th><th class="p-3">Opened</th></tr></thead>
                <tbody>
                @forelse ($breakdown as $incident)
                    <tr class="border-b dark:border-gray-700"><td class="p-3"><x-filament::badge :color="$incident->incident_type === 'featured_offer_lost' ? 'danger' : 'warning'">{{ str($incident->incident_type)->replace('_', ' ')->title() }}</x-filament::badge></td><td class="p-3">{{ $incident->sku }} · {{ $incident->product }}</td><td class="p-3">{{ $incident->brand ?: '—' }}</td><td class="p-3">{{ $incident->platform }}</td><td class="p-3">{{ $incident->responsible ?: ($incident->team ?: 'Owner/Admin fallback') }}</td><td class="p-3">{{ \Illuminate\Support\Carbon::parse($incident->opened_at)->format('d M Y, h:i A') }}</td></tr>
                @empty
                    <tr><td colspan="6" class="p-6 text-center text-gray-500">No active marketplace incidents.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
