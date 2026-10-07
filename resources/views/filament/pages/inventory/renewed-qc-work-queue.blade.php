<x-filament-panels::page>
    <x-filament::section heading="Renewed QC Work Queue / Pending Dispatch" description="Scan each physical unit into its exact Order Item. QC completion and scanning do not ship stock. Use Mark Shipped only when every Renewed unit is ready.">
        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
