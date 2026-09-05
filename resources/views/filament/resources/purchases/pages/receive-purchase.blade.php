<x-filament-panels::page>
    <div style="display:flex;gap:1.5rem;flex-wrap:wrap;padding:.9rem 1rem;border:1px solid rgba(128,128,128,.3);border-radius:.75rem;">
        <div><strong>Purchase:</strong> {{ $this->getRecord()->reference }}</div>
        <div><strong>Supplier:</strong> {{ $this->getRecord()->supplier?->name ?? 'No Supplier' }}</div>
    </div>

    <form wire:submit="receive" class="space-y-6">
        {{ $this->form }}
        <div class="flex flex-wrap gap-2">
            <x-filament::button type="button" color="gray" outlined wire:click="receiveRemaining">Receive Remaining</x-filament::button>
            <x-filament::button type="submit">Post Goods Received Note</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
