<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        <x-filament::section heading="Invoice Numbering & VAT" description="Company identity is managed only in Company Profile.">
            <div class="grid gap-4 md:grid-cols-3">
                @foreach (['invoicePrefix' => 'Invoice Prefix', 'nextInvoiceNumber' => 'Next Invoice Number', 'vatRate' => 'VAT Rate %'] as $field => $label)
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ $label }}</label>
                        <x-filament::input.wrapper><x-filament::input wire:model="{{ $field }}" /></x-filament::input.wrapper>
                        @error($field) <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        @foreach ([
            ['Tax Invoice Terms', 'termsEn', 'termsAr'],
            ['Quotation Terms', 'quotationTermsEn', 'quotationTermsAr'],
            ['Proforma Invoice Terms', 'proformaTermsEn', 'proformaTermsAr'],
        ] as [$heading, $english, $arabic])
            <x-filament::section :heading="$heading">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="{{ $english }}">English</label>
                        <textarea id="{{ $english }}" wire:model="{{ $english }}" rows="6" class="w-full max-w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"></textarea>
                        @error($english) <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="{{ $arabic }}">Arabic</label>
                        <textarea id="{{ $arabic }}" wire:model="{{ $arabic }}" dir="rtl" rows="6" class="w-full max-w-full rounded-lg border-gray-300 text-right dark:border-white/10 dark:bg-white/5"></textarea>
                        @error($arabic) <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </x-filament::section>
        @endforeach

        @can('invoice.settings.manage')
            <x-filament::button type="submit">Save Invoice Settings</x-filament::button>
        @endcan
    </form>
</x-filament-panels::page>
