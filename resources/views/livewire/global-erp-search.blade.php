<div
    x-data="{
        active: -1,
        openPalette() {
            this.active = -1;
            this.$dispatch('open-modal', { id: 'global-erp-search-palette' });
            setTimeout(() => document.getElementById('global-erp-search-input')?.focus(), 100);
        },
        items() { return Array.from(this.$refs.results?.querySelectorAll('[data-search-result]') ?? []) },
        move(step) {
            const items = this.items();
            if (! items.length) return;
            this.active = (this.active + step + items.length) % items.length;
            items[this.active]?.focus();
        },
        choose() { this.items()[Math.max(this.active, 0)]?.click() },
    }"
    x-on:keydown.window.prevent.ctrl.k="openPalette()"
    x-on:keydown.window.prevent.meta.k="openPalette()"
    class="relative flex items-center"
    data-testid="global-erp-search"
>
    <x-filament::icon-button
        type="button"
        color="gray"
        icon="heroicon-o-magnifying-glass"
        size="lg"
        label="Search ERP"
        tooltip="Search ERP (Ctrl K)"
        x-on:click="openPalette()"
        aria-keyshortcuts="Control+K Meta+K"
    />

    <x-filament::modal id="global-erp-search-palette" width="2xl" :close-button="false" teleport="body">
        <div class="space-y-4">
            <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass" suffix="Esc" inline-prefix inline-suffix>
                <input id="global-erp-search-input" aria-label="Search ERP" x-ref="input" wire:model.live.debounce.250ms="query" x-on:keydown.down.prevent="move(1)" x-on:keydown.up.prevent="move(-1)" x-on:keydown.enter.prevent="choose()" type="search" placeholder="Search ERP..." class="fi-input fi-input-has-inline-prefix fi-input-has-inline-suffix" autocomplete="off" />
            </x-filament::input.wrapper>

            <div wire:loading.delay class="py-8 text-center text-sm text-gray-500">Searching authorized ERP records…</div>
            <div wire:loading.remove x-ref="results" class="max-h-96 min-h-28 overflow-y-auto" data-testid="global-search-results">
                @if(mb_strlen(trim($query)) < $minimumLength)
                    <x-filament::empty-state :contained="false" heading="Type at least {{ $minimumLength }} characters to search." />
                @elseif($groups->isEmpty())
                    <x-filament::empty-state :contained="false" heading="No authorized results found." />
                @else
                    @php($resultIndex = 0)
                    <ul class="fi-global-search-results">
                        @foreach($groups as $group => $results)
                            <li class="fi-global-search-result-group">
                                <h3 class="fi-global-search-result-group-header">{{ $group }} ({{ $results->count() }})</h3>
                                <ul class="fi-global-search-result-group-results">
                                    @foreach($results as $result)
                                        <li class="fi-global-search-result">
                                            <a href="{{ $result->url }}" data-search-result x-on:mouseenter="active = {{ $resultIndex }}" class="fi-global-search-result-link">
                                                <h4 class="fi-global-search-result-heading">{{ $result->label }}</h4>
                                                <dl class="fi-global-search-result-details">
                                                    <div class="fi-global-search-result-detail"><dd class="fi-global-search-result-detail-value">{{ $result->description }}</dd></div>
                                                </dl>
                                            </a>
                                        </li>
                                        @php($resultIndex++)
                                    @endforeach
                                </ul>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </x-filament::modal>
</div>
