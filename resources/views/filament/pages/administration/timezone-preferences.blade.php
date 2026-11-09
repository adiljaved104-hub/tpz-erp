<x-filament-panels::page>
    <x-filament::section heading="Timezone Preferences" description="Technical timestamps remain stored in UTC. These settings control how dates and times are displayed to you.">
        <form wire:submit="save" class="space-y-6">
            <div class="grid gap-4 md:grid-cols-2">
                <label class="space-y-1 text-sm font-medium">
                    <span>Display timezone</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model="displayTimezone">
                            <option value="">Use regional/business fallback</option>
                            @foreach (timezone_identifiers_list() as $timezone)
                                <option value="{{ $timezone }}">{{ $timezone }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    @error('displayTimezone')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror
                </label>
                <div class="rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Currently used for display</div>
                    <div class="mt-1 font-semibold">{{ $effectiveTimezone }}</div>
                </div>
            </div>
            <label class="flex items-start gap-3 text-sm">
                <input type="checkbox" wire:model="useDeviceTimezone" class="mt-1 rounded border-gray-300 text-orange-500 focus:ring-orange-500" />
                <span><span class="font-medium">Use device timezone when no explicit timezone is set</span><span class="mt-1 block text-xs text-gray-500">The browser timezone is used only for display. It never changes official transaction timestamps or business dates.</span></span>
            </label>
            <div class="flex justify-end"><x-filament::button type="submit" color="warning">Save Preferences</x-filament::button></div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
