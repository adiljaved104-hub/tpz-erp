<x-filament-panels::page>
    <div class="mb-4 flex items-center justify-between rounded-xl bg-primary-50 px-4 py-3 text-sm text-primary-950 dark:bg-primary-400/10 dark:text-primary-100">
        <span>QC units needed for Orders</span>
        <strong>{{ $neededForOrders }}</strong>
    </div>
    {{ $this->table }}
</x-filament-panels::page>
