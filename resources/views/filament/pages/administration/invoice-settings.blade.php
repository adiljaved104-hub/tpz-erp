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
            ['Renewed Terms & Conditions', 'renewedTermsEn', 'renewedTermsAr'],
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
    @if($this->canManageFooterBranding())
        <form wire:submit="saveFooterBranding" class="mt-6 space-y-4">
            <x-filament::section heading="Footer Branding & Certification" description="Private local artwork only. Nothing is enabled or approved by default. Changes apply only to newly issued Tax Invoices; previous assets and invoice snapshots are retained.">
                <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">Upload clean JPEG, PNG or WebP artwork (maximum 2 MB, 2400 × 2400 pixels). The Owner must verify artwork rights and each certification or reseller/retailer claim. Changing a logo, caption, relationship or proof invalidates its approval. Disable changed entries until the Owner approves them again.</p>
                <div class="space-y-4">
                    <p class="text-xs text-gray-600 dark:text-gray-400">Open a brand to configure it. First upload artwork and record authentic rights/claim evidence, then have the Owner approve and save it. Approved entries can be enabled in a subsequent save. No brand or certification claim is active by default.</p>
                    @foreach(\App\Services\Invoices\InvoiceFooterBrandingService::BRANDS as $key => $label)
                        @php
                            $entry = $footerBranding['entries'][$key] ?? [];
                            $reviewStatus = app(\App\Services\Invoices\InvoiceFooterBrandingService::class)->reviewStatus($entry, filled($footerUploads[$key] ?? null));
                            $isApproved = str_starts_with($reviewStatus, 'Approved');
                            $mayToggleEnabled = $isApproved || ! empty($entry['enabled']);
                        @endphp
                        <details wire:key="footer-brand-{{ $key }}" class="group overflow-hidden rounded-xl border border-gray-300 bg-white dark:border-white/20 dark:bg-gray-900/40" @if($key === 'iso' || $errors->has('footerBranding.entries.'.$key.'.*') || $errors->has('footerUploads.'.$key)) open @endif>
                            <summary class="flex cursor-pointer list-none flex-wrap items-center gap-3 px-4 py-3 select-none [&::-webkit-details-marker]:hidden">
                                <span class="min-w-20 text-sm font-semibold text-gray-900 dark:text-white">{{ $label }}</span>
                                <span class="rounded-full px-2 py-1 text-xs font-medium {{ $isApproved ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200' : ($reviewStatus === 'Pending Owner approval' ? 'bg-amber-50 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200' : 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300') }}">{{ $reviewStatus }}</span>
                                <span class="ml-auto text-xs text-gray-500 dark:text-gray-400">Expand / collapse ▾</span>
                            </summary>
                            <fieldset class="border-t border-gray-200 p-4 dark:border-white/10">
                                <div class="mb-3 flex flex-wrap items-center gap-4">
                                    <label class="flex items-center gap-2 text-sm">
                                        <input type="checkbox" wire:model="footerBranding.entries.{{ $key }}.enabled" @disabled(! $mayToggleEnabled) class="rounded border-gray-300 text-primary-600">
                                        Enabled
                                    </label>
                                    @unless($isApproved)
                                        <span class="text-xs text-gray-500">Owner approval must be saved before enabling this entry.</span>
                                    @endunless
                                    @if($preview = app(\App\Services\Invoices\InvoiceFooterBrandingService::class)->image($entry['asset'] ?? null))
                                        <img src="{{ $preview }}" alt="Saved {{ $label }} artwork" style="max-width: 110px; max-height: 45px; object-fit: contain">
                                    @endif
                                </div>
                                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                    <div>
                                        <label for="footer-upload-{{ $key }}" class="block text-sm font-medium">Logo artwork</label>
                                        <input id="footer-upload-{{ $key }}" type="file" wire:model="footerUploads.{{ $key }}" accept="image/png,image/jpeg,image/webp" class="mt-1 block w-full text-sm">
                                    </div>
                                    <div>
                                        <label for="footer-caption-{{ $key }}" class="block text-sm font-medium">Caption</label>
                                        <x-filament::input.wrapper><x-filament::input id="footer-caption-{{ $key }}" wire:model="footerBranding.entries.{{ $key }}.caption" maxlength="80" /></x-filament::input.wrapper>
                                    </div>
                                    <div>
                                        <label for="footer-relationship-{{ $key }}" class="block text-sm font-medium">Relationship / claim</label>
                                        <x-filament::input.wrapper><x-filament::input.select id="footer-relationship-{{ $key }}" wire:model="footerBranding.entries.{{ $key }}.relationship">@if($key === 'iso')<option value="certification">Certification</option>@else<option value="brand">Brand only (no relationship claim)</option><option value="retailer">Retailer</option><option value="authorized_reseller">Authorized reseller</option>@endif</x-filament::input.select></x-filament::input.wrapper>
                                    </div>
                                    <div>
                                        <label for="footer-order-{{ $key }}" class="block text-sm font-medium">Position (1–7)</label>
                                        <x-filament::input.wrapper><x-filament::input id="footer-order-{{ $key }}" type="number" min="1" max="7" wire:model="footerBranding.entries.{{ $key }}.order" /></x-filament::input.wrapper>
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <label for="footer-evidence-{{ $key }}" class="block text-sm font-medium">Verified proof / artwork-rights reference (not printed)</label>
                                    <x-filament::input.wrapper><x-filament::input id="footer-evidence-{{ $key }}" wire:model="footerBranding.entries.{{ $key }}.evidence" maxlength="500" /></x-filament::input.wrapper>
                                </div>
                                @if(auth()->user()->employee?->role === \App\Enums\EmployeeRole::Owner)
                                    <div class="mt-3 flex flex-wrap gap-4 text-sm">
                                        <label class="flex items-center gap-2"><input type="checkbox" wire:model="footerBranding.entries.{{ $key }}.approve" class="rounded border-gray-300 text-primary-600"> I verified and approve this artwork and claim</label>
                                        <label class="flex items-center gap-2"><input type="checkbox" wire:model="footerBranding.entries.{{ $key }}.revoke_approval" class="rounded border-gray-300 text-primary-600"> Revoke previous approval</label>
                                    </div>
                                @endif
                                @foreach(['enabled', 'caption', 'relationship', 'order', 'evidence'] as $field)
                                    @error('footerBranding.entries.'.$key.'.'.$field)<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror
                                @endforeach
                                @error('footerUploads.'.$key)<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror
                            </fieldset>
                        </details>
                    @endforeach
                    <fieldset class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                        <legend class="px-2 text-sm font-semibold">Bilingual certification statement</legend>
                        <label class="mb-3 flex items-center gap-2 text-sm"><input type="checkbox" wire:model="footerBranding.statement.enabled" class="rounded border-gray-300 text-primary-600"> Enable verified statement (requires the approved ISO logo entry)</label>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label for="footer-statement-en" class="mb-1 block text-sm font-medium text-gray-800 dark:text-gray-100">English</label>
                                <textarea id="footer-statement-en" wire:model="footerBranding.statement.en" rows="4" maxlength="500" dir="ltr" style="border: 1px solid #9ca3af; min-height: 110px; box-sizing: border-box;" class="block w-full max-w-full rounded-lg border border-solid border-gray-400 bg-white p-3 text-gray-900 shadow-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-500 dark:bg-gray-900 dark:text-white"></textarea>
                            </div>
                            <div>
                                <label for="footer-statement-ar" class="mb-1 block text-sm font-medium text-gray-800 dark:text-gray-100">Arabic</label>
                                <textarea id="footer-statement-ar" wire:model="footerBranding.statement.ar" rows="4" maxlength="500" dir="rtl" style="border: 1px solid #9ca3af; min-height: 110px; box-sizing: border-box;" class="block w-full max-w-full rounded-lg border border-solid border-gray-400 bg-white p-3 text-right text-gray-900 shadow-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-500 dark:bg-gray-900 dark:text-white"></textarea>
                            </div>
                        </div>
                        <label for="footer-statement-evidence" class="block text-sm font-medium">Verified certification proof reference (not printed)</label><x-filament::input.wrapper><x-filament::input id="footer-statement-evidence" wire:model="footerBranding.statement.evidence" maxlength="500" /></x-filament::input.wrapper>
                        @if(auth()->user()->employee?->role === \App\Enums\EmployeeRole::Owner)
                            <div class="mt-3 flex flex-wrap gap-4 text-sm">
                                <label class="flex items-center gap-2"><input type="checkbox" wire:model="footerBranding.statement.approve" class="rounded border-gray-300 text-primary-600"> I verified and approve both statements</label>
                                <label class="flex items-center gap-2"><input type="checkbox" wire:model="footerBranding.statement.revoke_approval" class="rounded border-gray-300 text-primary-600"> Revoke previous approval</label>
                            </div>
                        @endif
                        @foreach(['enabled', 'en', 'ar', 'evidence'] as $field)
                            @error('footerBranding.statement.'.$field)<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror
                        @endforeach
                    </fieldset>
                </div>
                @error('footerUploads')<p class="text-sm text-danger-600">{{ $message }}</p>@enderror
                <div class="mt-4"><x-filament::button type="submit" wire:loading.attr="disabled" wire:target="saveFooterBranding,footerUploads">Save Footer Branding</x-filament::button><span wire:loading wire:target="footerUploads" class="ml-3 text-sm">Uploading artwork…</span></div>
            </x-filament::section>
        </form>
    @endif
</x-filament-panels::page>
