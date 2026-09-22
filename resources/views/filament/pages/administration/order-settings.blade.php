<x-filament-panels::page>
    <x-filament::section heading="Order Amendment Window" description="After an Order is reserved, changes to its external reference, quantities and prices require a reason. The default window is one hour. Owner/Admin override is recorded after expiry; shipped Orders cannot be amended.">
        <form wire:submit="save" class="space-y-4">
            <div>
                <label for="amendmentWindowHours" class="block text-sm font-medium">Normal amendment window</label>
                <x-filament::input.wrapper>
                    <select id="amendmentWindowHours" wire:model="amendmentWindowHours" class="w-full border-0 bg-transparent dark:bg-transparent">
                        @foreach ([1, 2, 5, 12, 24] as $hours)
                            <option value="{{ $hours }}">{{ $hours }} {{ $hours === 1 ? 'hour' : 'hours' }}</option>
                        @endforeach
                    </select>
                </x-filament::input.wrapper>
                @error('amendmentWindowHours') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
            </div>
            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="save">Save Order Settings</x-filament::button>
        </form>
    </x-filament::section>
</x-filament-panels::page>
