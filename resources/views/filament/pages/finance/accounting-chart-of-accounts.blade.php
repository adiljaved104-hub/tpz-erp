<x-filament-panels::page>
    <div class="space-y-5">
        <x-accounting.page-header title="Chart of Accounts" description="Browse the selected book's account hierarchy and manage accounts you are authorized to maintain.">
            <x-slot:actions>
                <label class="flex min-w-52 flex-col gap-1 text-xs font-medium text-gray-600 dark:text-gray-300">
                    <span>Accounting book</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="bookId" aria-label="Accounting book">
                            @foreach ($books as $item)<option value="{{ $item->id }}">{{ $item->name }} · {{ $item->currency }}</option>@endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
            </x-slot:actions>
        </x-accounting.page-header>

        <x-filament::section compact>
            <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-base font-semibold">Accounts</h2>
                    @if ($book)<p class="mt-1 text-sm text-gray-500">{{ $book->name }} · {{ $book->currency }} · {{ $accounts->count() }} matching account{{ $accounts->count() === 1 ? '' : 's' }}</p>@endif
                </div>
                <x-accounting.toolbar class="mb-0 flex-1 lg:justify-end">
                    <label class="sr-only" for="account-search">Search accounts</label>
                    <x-filament::input.wrapper class="w-full sm:max-w-xs" prefix-icon="heroicon-m-magnifying-glass">
                        <x-filament::input id="account-search" wire:model.live.debounce.250ms="search" type="search" placeholder="Search name, number, parent" />
                    </x-filament::input.wrapper>
                    <label class="sr-only" for="account-type-filter">Account type</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="account-type-filter" wire:model.live="typeFilter" aria-label="Filter account type">
                            <option value="">All types</option>
                            @foreach ($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    <label class="sr-only" for="account-status-filter">Account status</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="account-status-filter" wire:model.live="statusFilter" aria-label="Filter account status">
                            <option value="active">Active accounts</option><option value="inactive">Inactive accounts</option><option value="all">All statuses</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </x-accounting.toolbar>
            </div>

            @if ($accounts->isNotEmpty())
                <x-accounting.data-table minimum-width="58rem">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-white/5"><tr><th scope="col" class="px-4 py-3">Account number</th><th scope="col" class="px-4 py-3">Account name</th><th scope="col" class="px-4 py-3">Type</th><th scope="col" class="px-4 py-3">Parent account</th><th scope="col" class="px-4 py-3">Normal balance</th><th scope="col" class="px-4 py-3">Posting</th><th scope="col" class="px-4 py-3">Status</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($accounts as $account)
                            <tr class="hover:bg-gray-50/80 dark:hover:bg-white/[0.03]">
                                <td class="whitespace-nowrap px-4 py-3 font-mono text-xs tabular-nums">{{ $account->number }}</td>
                                <td class="px-4 py-3"><div class="flex items-center gap-2" style="padding-left: {{ min($account->hierarchy_depth * 1.25, 5) }}rem">@if ($account->hierarchy_depth > 0)<span aria-hidden="true" class="text-gray-400">↳</span>@endif<span class="font-medium text-gray-900 dark:text-white">{{ $account->name }}</span></div></td>
                                <td class="whitespace-nowrap px-4 py-3">{{ ucfirst($account->account_type->value) }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $account->parent?->name ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ ucfirst($account->normal_balance) }}</td>
                                <td class="px-4 py-3">{{ $account->is_posting ? 'Posting account' : 'Header account' }}</td>
                                <td class="px-4 py-3"><x-accounting.status-badge :status="$account->active ? 'Active' : 'Inactive'" :tone="$account->active ? 'success' : 'gray'" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-accounting.data-table>
            @else
                <x-accounting.empty-state title="No accounts match these filters" description="Adjust the search or filters, or create an account if you have account-management permission." />
            @endif
        </x-filament::section>

        @if ($canManageChart)
            <x-filament::section heading="Create account" description="Subaccounts must belong to the selected book and match their parent's account type." collapsible collapsed compact>
                <form wire:submit="saveAccount" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    <label class="grid content-start gap-1 text-sm font-medium"><span>Account number</span><x-filament::input.wrapper><x-filament::input wire:model="number" placeholder="e.g. 1010" aria-describedby="account-number-help" /></x-filament::input.wrapper><span id="account-number-help" class="text-xs font-normal text-gray-500">Letters, numbers, periods and hyphens.</span>@error('number')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror</label>
                    <label class="grid content-start gap-1 text-sm font-medium"><span>Account name</span><x-filament::input.wrapper><x-filament::input wire:model="name" placeholder="Account name" />@error('name')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror</x-filament::input.wrapper></label>
                    <label class="grid content-start gap-1 text-sm font-medium"><span>Account type</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="type">@foreach ($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>@error('type')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror</label>
                    <label class="grid content-start gap-1 text-sm font-medium"><span>Parent account</span><x-filament::input.wrapper><x-filament::input.select wire:model="parentId"><option value="">Top-level account</option>@foreach ($parentAccounts as $parent)<option value="{{ $parent->id }}">{{ $parent->number }} — {{ $parent->name }}</option>@endforeach</x-filament::input.select>@error('parentId')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror</x-filament::input.wrapper></label>
                    <div class="grid content-start gap-3">
                        <label class="flex items-center gap-2 pt-2 text-sm"><input type="checkbox" wire:model="posting" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500"><span>Posting account</span></label>
                        <x-filament::button type="submit" icon="heroicon-m-plus">Create account</x-filament::button>
                    </div>
                    @error('bookId')<p class="text-xs text-danger-600 sm:col-span-2">{{ $message }}</p>@enderror
                </form>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
