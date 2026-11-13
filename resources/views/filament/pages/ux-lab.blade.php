<x-filament-panels::page>
    <x-filament::section compact heading="TPZ UX Lab" description="Owner-only, read-only prototypes. No operational records, balances, reservations, or permissions are changed here.">
        <div class="grid gap-3 lg:grid-cols-4">
            <label class="lg:col-span-2"><span class="mb-1 block text-xs font-semibold">Prototype module</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="module" aria-label="Prototype module">@foreach ($modules as $key => $item)<option value="{{ $key }}">{{ $item['label'] }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
            <label><span class="mb-1 block text-xs font-semibold">Experience mode</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="mode"><option value="simple">Simple</option><option value="advanced">Advanced</option></x-filament::input.select></x-filament::input.wrapper></label>
            <label><span class="mb-1 block text-xs font-semibold">Preview</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="viewport"><option value="desktop">Desktop</option><option value="mobile">Mobile</option></x-filament::input.select></x-filament::input.wrapper></label>
        </div>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
            <span>{{ $modules[$module]['description'] ?? '' }}</span>
            <div class="flex flex-wrap gap-2"><x-filament::button size="xs" color="gray" wire:click="resetDemo">Reset demo</x-filament::button><x-filament::button size="xs" :color="$comparison === 'current' ? 'primary' : 'gray'" wire:click="$set('comparison', 'current')">Current</x-filament::button><x-filament::button size="xs" :color="$comparison === 'proposed' ? 'primary' : 'gray'" wire:click="$set('comparison', 'proposed')">Proposed</x-filament::button>@if ($mode === 'advanced' && $comparison === 'proposed')<x-filament::button size="xs" color="gray" wire:click="toggleLayout">{{ $demoLayout === 'cards' ? 'Table layout' : 'Card layout' }}</x-filament::button>@endif</div>
        </div>
    </x-filament::section>

    <div class="{{ $viewport === 'mobile' ? 'mx-auto max-w-md' : '' }} space-y-4">
        @if ($comparison === 'current')
            <x-filament::section compact heading="Current workflow — reference" description="Illustrative navigation from the existing ERP. This preview does not modify or duplicate an operational page.">
                <ol class="list-decimal space-y-2 pl-5 text-sm">
                    <li>Open the relevant ERP module from navigation.</li>
                    <li>Locate the record, then open its detailed page.</li>
                    <li>Find a permitted action, complete its form, and confirm the result.</li>
                </ol>
                <p class="mt-3 text-xs text-gray-500">Selected module: {{ $modules[$module]['label'] ?? 'My Workspace' }}. Switch to Proposed to try the interactive alternative.</p>
            </x-filament::section>
        @else
        <div class="grid gap-3 {{ $viewport === 'mobile' ? 'grid-cols-1' : 'sm:grid-cols-3' }}">
            @foreach ($cards as $card)
                <x-filament::section compact>
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $card['label'] }}</div>
                    <div class="mt-1 text-2xl font-bold">{{ $card['value'] }}</div>
                    <div class="mt-1 text-xs text-gray-500">{{ $card['detail'] }}</div>
                </x-filament::section>
            @endforeach
        </div>

        @if (in_array($module, ['my-workspace', 'dashboard'], true))
        <x-filament::section compact heading="{{ $module === 'dashboard' ? 'Dashboard action queue' : 'My Workspace prototype' }}" description="Simulated work queue. All records, actions and statuses are demonstration data only.">
            <div class="mb-3 grid gap-2 {{ $mode === 'advanced' ? 'sm:grid-cols-3' : '' }}">
                @if ($mode === 'advanced')
                <x-filament::input.wrapper><x-filament::input type="search" wire:model.live.debounce.250ms="queueSearch" placeholder="Search demo work…" aria-label="Search demo work" /></x-filament::input.wrapper>
                <x-filament::input.wrapper><x-filament::input.select wire:model.live="queueFilter" aria-label="Filter demo work"><option value="all">All work</option><option value="Approval">Approvals</option><option value="Sales Order">Sales Orders</option><option value="Web Sales">Web Sales</option><option value="Transfer">Transfers</option></x-filament::input.select></x-filament::input.wrapper>
                <div class="flex items-center justify-end text-xs text-gray-500">Illustrative role: {{ ucfirst($rolePreview) }} · actual permission checks remain enforced</div>
                @endif
            </div>
            @if ($demoLayout === 'table' && $mode === 'advanced')
                <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10"><table class="w-full min-w-[720px] text-sm"><thead class="bg-gray-50 text-left text-xs uppercase dark:bg-white/5"><tr><th class="px-3 py-2">Type</th><th class="px-3 py-2">Work</th><th class="px-3 py-2">Status</th><th class="px-3 py-2">Action</th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-white/5">@foreach ($queue as $item)<tr wire:key="ux-queue-{{ $item['id'] }}"><td class="px-3 py-2">{{ $item['type'] }}</td><td class="px-3 py-2"><button class="font-semibold text-primary-600 hover:underline" wire:click="openPreview({{ $item['id'] }})">{{ $item['title'] }}</button><div class="text-xs text-gray-500">{{ $item['detail'] }}</div></td><td class="px-3 py-2"><x-filament::badge>{{ $item['status'] }}</x-filament::badge></td><td class="px-3 py-2"><x-filament::button size="xs" color="gray" wire:click="simulateAction({{ $item['id'] }}, 'read')">Mark read</x-filament::button></td></tr>@endforeach</tbody></table></div>
            @else
                <div class="grid gap-3 md:grid-cols-2">
                    @foreach ($queue as $item)
                        <div wire:key="ux-queue-card-{{ $item['id'] }}" class="rounded-xl border border-gray-200 p-4 dark:border-white/10"><div class="flex items-start justify-between gap-3"><div><div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $item['type'] }}</div><button class="mt-1 text-left font-semibold text-primary-600 hover:underline" wire:click="openPreview({{ $item['id'] }})">{{ $item['title'] }}</button><p class="mt-1 text-sm text-gray-500">{{ $item['detail'] }}</p></div><x-filament::badge>{{ $item['status'] }}</x-filament::badge></div><div class="mt-3 flex flex-wrap gap-2"><x-filament::button size="xs" color="gray" wire:click="simulateAction({{ $item['id'] }}, 'read')">Mark read</x-filament::button>@if ($item['type'] === 'Approval')<x-filament::button size="xs" wire:click="simulateAction({{ $item['id'] }}, 'approve')">Approve (demo)</x-filament::button>@elseif ($item['type'] === 'Sales Order')<x-filament::button size="xs" wire:click="simulateAction({{ $item['id'] }}, 'reserve')">Reserve (demo)</x-filament::button>@endif</div></div>
                    @endforeach
                </div>
            @endif
            @if (count($queue) === 0)<div class="py-5 text-center text-sm text-gray-500">No simulated work matches these filters.</div>@endif
        </x-filament::section>
        @endif

        @if ($module === 'sales-orders' || $module === 'web-sales')
            <x-filament::section compact heading="Interactive order builder" description="Search, line editing, totals, and reservation/shipment previews are simulated in memory only.">
                <div class="space-y-2">@foreach ($demoOrderLines as $index => $line)<div class="grid items-center gap-2 rounded-lg border border-gray-200 p-2 sm:grid-cols-[1fr_auto_auto_auto] dark:border-white/10"><div><div class="font-semibold">{{ $line['sku'] }}</div><div class="text-xs text-gray-500">{{ $line['title'] }}</div></div><x-filament::input.wrapper><x-filament::input type="number" min="1" wire:change="updateDemoQuantity({{ $index }}, $event.target.value)" value="{{ $line['qty'] }}" aria-label="Quantity" /></x-filament::input.wrapper><span class="text-sm">AED {{ number_format($line['qty'] * $line['price'], 2) }}</span><x-filament::button size="xs" color="gray" wire:click="removeDemoOrderLine({{ $index }})">Remove</x-filament::button></div>@endforeach<div class="flex flex-wrap items-center justify-between gap-2"><x-filament::button size="sm" color="gray" wire:click="addDemoOrderLine">Add demo product</x-filament::button><span class="font-semibold">Demo total: AED {{ number_format($orderTotal, 2) }}</span></div><div class="text-xs text-gray-500">Save, reserve, and ship buttons are intentionally simulated and cannot create operational transactions.</div></div>
            </x-filament::section>
        @endif
            <div class="grid gap-3 md:grid-cols-3">
                @foreach ([['title' => 'Next action', 'body' => 'Open the oldest actionable item first.', 'tone' => 'primary'], ['title' => 'Context first', 'body' => 'Show customer, product, warehouse, and responsibility context together.', 'tone' => 'success'], ['title' => 'Safe actions', 'body' => 'Keep write actions behind the existing permission and workflow gates.', 'tone' => 'warning']] as $item)
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10"><div class="font-semibold">{{ $item['title'] }}</div><p class="mt-1 text-sm text-gray-500">{{ $item['body'] }}</p></div>
                @endforeach
            </div>
        @endif
    </div>

    <x-filament::section compact heading="Prototype feedback" description="Feedback remains in this disposable Livewire session and is not written to ERP records.">
        <div class="space-y-3"><x-filament::input.wrapper><textarea wire:model="feedback" rows="3" class="fi-input block w-full border-0 bg-transparent px-3 py-2 text-sm outline-none" placeholder="What should Owner/Admin review next?"></textarea></x-filament::input.wrapper><div class="flex flex-wrap items-center gap-3"><x-filament::button size="sm" wire:click="captureFeedback">Record session note</x-filament::button><x-filament::button size="sm" color="gray" wire:click="downloadFeedback">Export note</x-filament::button>@if ($feedbackCaptured)<span class="text-sm text-success-600">Session note captured.</span>@endif</div></div>
    </x-filament::section>

    @if ($drawerOpen && $selected)
        <div class="fixed inset-0 z-40 bg-gray-950/30" wire:click="closePreview"></div><aside class="fixed inset-y-0 right-0 z-50 w-full max-w-md overflow-y-auto border-l border-gray-200 bg-white p-5 shadow-xl dark:border-white/10 dark:bg-gray-900" aria-label="Demo record preview"><div class="flex items-center justify-between"><h2 class="text-lg font-semibold">Record preview</h2><x-filament::button size="xs" color="gray" wire:click="closePreview">Close</x-filament::button></div><div class="mt-5 space-y-3"><x-filament::badge>{{ $selected['type'] }}</x-filament::badge><h3 class="text-xl font-bold">{{ $selected['title'] }}</h3><p class="text-sm text-gray-500">{{ $selected['detail'] }}</p><div class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/5"><div><span class="text-gray-500">Owner:</span> {{ $selected['owner'] }}</div><div><span class="text-gray-500">Demo status:</span> {{ $selected['status'] }}</div><div><span class="text-gray-500">Data source:</span> isolated simulation state</div></div></div></aside>
    @endif
</x-filament-panels::page>
