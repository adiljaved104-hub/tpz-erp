@if ($responsibilities !== null)
    <x-filament::section compact data-dashboard-section="responsibilities">
        <x-slot name="heading">My Responsibilities</x-slot>
        <x-slot name="afterHeader"><x-filament::link :href="$responsibilities['url']">Open My Inventory</x-filament::link></x-slot>
        @if ($responsibilities['items']->isEmpty())
            <p class="erp-dashboard-muted">No active responsibility assignments.</p>
        @else
            <div class="erp-dashboard-responsibilities">
                @foreach ($responsibilities['items'] as $index => $item)
                    <x-filament::badge color="info" wire:key="responsibility-{{ $index }}">{{ $item }}</x-filament::badge>
                @endforeach
            </div>
        @endif
    </x-filament::section>
@endif
