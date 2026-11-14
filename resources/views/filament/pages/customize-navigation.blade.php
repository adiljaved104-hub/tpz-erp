<x-filament-panels::page>
    <div class="space-y-5">
        <x-filament::section
            heading="Customize Navigation"
            description="Hide modules you do not need and drag groups into your preferred order. Authorization remains unchanged."
        >
            @if (auth()->user()?->employee?->role === \App\Enums\EmployeeRole::Owner)
                <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-primary-200 bg-primary-50 p-4 dark:border-primary-500/20 dark:bg-primary-500/10">
                    <div><h2 class="text-sm font-semibold">Simplified workspace navigation</h2><p class="mt-1 text-xs text-gray-600 dark:text-gray-300">Preview Home, Sales, Inventory &amp; Warehouse, Purchasing, Returns &amp; QC, Finance &amp; Accounting, and Team &amp; HR without changing anyone else’s navigation.</p></div>
                    <x-filament::button type="button" size="sm" :color="$workspaceMode ? 'success' : 'gray'" wire:click="toggleWorkspaceMode">{{ $workspaceMode ? 'Enabled for me' : 'Enable for me' }}</x-filament::button>
                </div>
            @endif
            <div
                class="space-y-3"
                data-navigation-group-list
                x-data
                x-on:dragover.prevent="
                    const dragged = $el.querySelector('[data-dragging=true]');
                    const target = $event.target.closest('[data-navigation-group]');
                    if (! dragged || ! target || dragged === target) return;
                    const rect = target.getBoundingClientRect();
                    const before = $event.clientY < (rect.top + rect.height / 2);
                    target.parentNode.insertBefore(dragged, before ? target : target.nextSibling);
                "
            >
                @foreach ($navigationGroups as $group)
                    <article
                        class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900"
                        data-navigation-group
                        data-group-key="{{ $group['key'] }}"
                        data-dragging="false"
                        wire:key="navigation-group-{{ $group['key'] }}"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $group['label'] }}</h2>
                            <button
                                type="button"
                                class="inline-flex cursor-move items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-gray-500 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/5"
                                draggable="true"
                                aria-label="Move {{ $group['label'] }} group"
                                x-on:dragstart="
                                    const item = $el.closest('[data-navigation-group]');
                                    item.dataset.dragging = 'true';
                                    $event.dataTransfer.effectAllowed = 'move';
                                    $event.dataTransfer.setData('text/plain', item.dataset.groupKey);
                                "
                                x-on:dragend="
                                    const item = $el.closest('[data-navigation-group]');
                                    item.dataset.dragging = 'false';
                                    const order = Array.from(item.parentNode.querySelectorAll('[data-navigation-group]')).map((element) => element.dataset.groupKey);
                                    $wire.reorderNavigationGroups(order);
                                "
                            >
                                <x-heroicon-m-bars-3 class="h-4 w-4" /> Move group
                            </button>
                        </div>

                        <div class="mt-3 divide-y divide-gray-100 overflow-hidden rounded-lg border border-gray-200 dark:divide-white/5 dark:border-white/10">
                            @foreach ($group['items'] as $item)
                                <div class="flex min-w-0 items-center justify-between gap-3 px-3 py-2.5">
                                    <div class="min-w-0">
                                        <div class="truncate text-sm font-medium text-gray-800 dark:text-gray-100">{{ $item['label'] }}</div>
                                        @if ($item['parent'])
                                            <div class="text-xs text-gray-500 dark:text-gray-400">Nested navigation item</div>
                                        @endif
                                    </div>
                                    @if ($item['protected'])
                                        <x-filament::badge color="gray">Always available</x-filament::badge>
                                    @else
                                        <x-filament::button
                                            type="button"
                                            size="xs"
                                            :color="in_array($item['key'], $hiddenItems, true) ? 'gray' : 'success'"
                                            :outlined="in_array($item['key'], $hiddenItems, true)"
                                            wire:click="toggleNavigationItem('{{ $item['key'] }}')"
                                        >
                                            {{ in_array($item['key'], $hiddenItems, true) ? 'Show' : 'Visible' }}
                                        </x-filament::button>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="mt-5 flex justify-end">
                <x-filament::button type="button" color="danger" outlined wire:click="resetNavigation" wire:confirm="Reset your navigation to the authorized default?">
                    Reset to Default
                </x-filament::button>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
