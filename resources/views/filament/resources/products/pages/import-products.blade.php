<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section heading="Bulk Product Import" description="Upload, validate and preview the file before any Product is created or updated.">
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium">Import Mode <span class="text-danger-600">*</span></label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model="mode">
                            <option value="create">Create New Products</option>
                            <option value="upsert">Create / Update Products</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    @error('mode') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">CSV or XLSX File <span class="text-danger-600">*</span></label>
                    <input type="file" wire:model="file" accept=".csv,.txt,.xlsx,.xls" class="block w-full rounded-lg border border-gray-300 p-2 text-sm dark:border-white/10" />
                    @error('file') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="mt-4 flex flex-wrap gap-3">
                <x-filament::button wire:click="previewImport" wire:loading.attr="disabled" wire:target="previewImport,file">Validate &amp; Preview</x-filament::button>
                <x-filament::button color="gray" wire:click="downloadTemplate">Download Import Template</x-filament::button>
            </div>
        </x-filament::section>

        @if ($preview)
            <x-filament::section heading="Import Preview" description="No Product data has been written yet.">
                <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach (['new' => 'Valid New', 'update' => 'Existing to Update', 'duplicate' => 'Possible Duplicates', 'invalid' => 'Invalid Rows'] as $key => $label)
                        <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10"><div class="text-sm text-gray-500">{{ $label }}</div><div class="text-2xl font-bold">{{ $preview['counts'][$key] }}</div></div>
                    @endforeach
                </div>
                <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
                    <table class="w-full min-w-[760px] text-left text-sm">
                        <thead class="bg-gray-50 dark:bg-white/5"><tr><th class="p-3">Row</th><th class="p-3">Result</th><th class="p-3">Product</th><th class="p-3">Details</th></tr></thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($preview['rows'] as $row)
                                <tr><td class="p-3 align-top">{{ $row['row'] }}</td><td class="p-3 align-top font-medium">{{ ucfirst($row['status']) }}</td><td class="p-3 align-top">{{ $row['data']['sku'] ?: 'New TPZ SKU' }} · {{ $row['data']['name'] ?? $row['data']['model'] }}</td><td class="p-3 align-top">
                                    @foreach ($row['errors'] as $message)<p class="text-danger-600">Row {{ $row['row'] }}: {{ $message }}</p>@endforeach
                                    @foreach ($row['warnings'] as $message)<p class="text-warning-600">Row {{ $row['row'] }}: {{ $message }}</p>@endforeach
                                    @if ($row['errors'] === [] && $row['warnings'] === [])<p class="text-success-600">Ready to import.</p>@endif
                                </td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if (($preview['counts']['duplicate'] ?? 0) > 0)
                    <div class="mt-4">
                        <label class="mb-1 block text-sm font-medium">Reason to Continue With Possible Duplicates <span class="text-danger-600">*</span></label>
                        <textarea wire:model="duplicateOverrideReason" rows="3" maxlength="500" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"></textarea>
                        @error('duplicateOverrideReason') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                    </div>
                @endif
                <div class="mt-4">
                    <x-filament::button wire:click="import" wire:loading.attr="disabled" wire:target="import" :disabled="($preview['counts']['invalid'] ?? 0) > 0">
                        <span wire:loading.remove wire:target="import">Confirm Import</span><span wire:loading wire:target="import">Importing...</span>
                    </x-filament::button>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
