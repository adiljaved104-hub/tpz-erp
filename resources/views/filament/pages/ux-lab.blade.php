<x-filament-panels::page>
    <x-filament::section compact heading="TPZ UX Lab" description="Owner-only, read-only prototypes. No operational records, balances, reservations, or permissions are changed here.">
        <div class="grid gap-3 lg:grid-cols-4">
            <label class="lg:col-span-2"><span class="mb-1 block text-xs font-semibold">Prototype module</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="module" aria-label="Prototype module">@foreach ($modules as $key => $item)<option value="{{ $key }}">{{ $item['label'] }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
            <label><span class="mb-1 block text-xs font-semibold">Experience mode</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="mode"><option value="simple">Simple</option><option value="advanced">Advanced</option></x-filament::input.select></x-filament::input.wrapper></label>
            <label><span class="mb-1 block text-xs font-semibold">Preview</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="viewport"><option value="desktop">Desktop</option><option value="mobile">Mobile</option></x-filament::input.select></x-filament::input.wrapper></label>
        </div>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
            <span>{{ $modules[$module]['description'] ?? '' }}</span>
            <div class="flex gap-2"><x-filament::button size="xs" color="gray" wire:click="resetDemo">Reset demo</x-filament::button><x-filament::button size="xs" color="gray" wire:click="$set('comparison', 'current')">Current</x-filament::button><x-filament::button size="xs" color="primary" wire:click="$set('comparison', 'proposed')">Proposed</x-filament::button></div>
        </div>
    </x-filament::section>

    <div class="{{ $viewport === 'mobile' ? 'mx-auto max-w-md' : '' }} space-y-4">
        <div class="grid gap-3 sm:grid-cols-3">
            @foreach ($cards as $card)
                <x-filament::section compact>
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $card['label'] }}</div>
                    <div class="mt-1 text-2xl font-bold">{{ $card['value'] }}</div>
                    <div class="mt-1 text-xs text-gray-500">{{ $card['detail'] }}</div>
                </x-filament::section>
            @endforeach
        </div>

        <x-filament::section compact heading="My Workspace prototype" description="A single prioritized queue for work already visible to the signed-in employee.">
            <div class="grid gap-3 md:grid-cols-3">
                @foreach ([['title' => 'Next action', 'body' => 'Open the oldest actionable item first.', 'tone' => 'primary'], ['title' => 'Context first', 'body' => 'Show customer, product, warehouse, and responsibility context together.', 'tone' => 'success'], ['title' => 'Safe actions', 'body' => 'Keep write actions behind the existing permission and workflow gates.', 'tone' => 'warning']] as $item)
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10"><div class="font-semibold">{{ $item['title'] }}</div><p class="mt-1 text-sm text-gray-500">{{ $item['body'] }}</p></div>
                @endforeach
            </div>
        </x-filament::section>
    </div>

    <x-filament::section compact heading="Prototype feedback" description="Feedback remains in this disposable Livewire session and is not written to ERP records.">
        <div class="space-y-3"><x-filament::input.wrapper><textarea wire:model="feedback" rows="3" class="fi-input block w-full border-0 bg-transparent px-3 py-2 text-sm outline-none" placeholder="What should Owner/Admin review next?"></textarea></x-filament::input.wrapper><div class="flex items-center gap-3"><x-filament::button size="sm" wire:click="captureFeedback">Record session note</x-filament::button>@if ($feedbackCaptured)<span class="text-sm text-success-600">Session note captured.</span>@endif</div></div>
    </x-filament::section>
</x-filament-panels::page>
