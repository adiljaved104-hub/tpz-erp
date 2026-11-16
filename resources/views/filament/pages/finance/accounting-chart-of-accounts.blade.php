<x-accounting.workspace current="chart" title="Chart of Accounts" description="A structured view of the selected book’s accounts, hierarchy and status." :books="$books" :book="$book">
    <section class="tpz-accounting-panel">
        <div class="tpz-accounting-toolbar">
            <label class="sr-only" for="account-search">Search accounts</label><input id="account-search" type="search" wire:model.live.debounce.250ms="search" placeholder="Search account name, number, or parent…">
            <label class="sr-only" for="account-type-filter">Account type</label><select id="account-type-filter" wire:model.live="typeFilter" aria-label="Filter account type"><option value="">All account types</option>@foreach($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
            <label class="sr-only" for="account-status-filter">Account status</label><select id="account-status-filter" wire:model.live="statusFilter" aria-label="Filter account status"><option value="active">Active accounts</option><option value="inactive">Inactive accounts</option><option value="all">All statuses</option></select>
            @if($canManageChart)<button type="button" class="tpz-accounting-btn is-primary" onclick="document.getElementById('account-create-form')?.scrollIntoView({behavior:'smooth'});document.getElementById('account-create-form')?.querySelector('input')?.focus()">＋ New account</button>@endif
        </div>
        <div class="mb-3 flex flex-wrap justify-between gap-2 text-xs text-gray-500">@if($book)<span>{{ $book->name }} · {{ $book->currency }} · {{ $accounts->count() }} account{{ $accounts->count() === 1 ? '' : 's' }}</span>@endif @if($canViewBalances)<span>Posted balance as of {{ now()->format('d M Y') }}</span>@else<span>Balances hidden · Trial Balance permission required</span>@endif</div>
        @if($accounts->isNotEmpty())
            <div class="tpz-accounting-table-wrap"><table class="tpz-accounting-data-table" aria-label="Chart of Accounts"><thead><tr><th>Number</th><th>Account name</th><th>Type</th><th>Parent account</th><th>Normal balance</th><th>Posting</th>@if($canViewBalances)<th class="text-right">Balance · {{ $book?->currency }}</th>@endif<th>Status</th></tr></thead><tbody>
                @foreach($accounts as $account)
                    <tr><td class="whitespace-nowrap font-mono text-xs">{{ $account->number }}</td><td><span style="padding-left:{{ min($account->hierarchy_depth * 1.25, 5) }}rem">@if($account->hierarchy_depth > 0)<span class="mr-1 text-gray-400">↳</span>@endif<strong>{{ $account->name }}</strong></span></td><td class="whitespace-nowrap">{{ ucfirst($account->account_type->value) }}</td><td>{{ $account->parent?->name ?? '—' }}</td><td>{{ ucfirst($account->normal_balance) }}</td><td>{{ $account->is_posting ? 'Posting account' : 'Header account' }}</td>@if($canViewBalances)<td class="text-right tabular-nums">{{ number_format((float) ($balances[$account->id]['balance'] ?? 0), 2) }}</td>@endif<td><x-accounting.status-badge :status="$account->active ? 'Active' : 'Inactive'" :tone="$account->active ? 'success' : 'gray'" /></td></tr>
                @endforeach
            </tbody></table></div>
        @else
            <div class="tpz-accounting-disabled text-center">No accounts match these filters. Adjust the search or status, or create an account if your role permits.</div>
        @endif
    </section>
    @if($canManageChart)
        <section class="tpz-accounting-panel mt-4" id="account-create-form">
            <h2 class="mb-1 text-base font-semibold">Create account</h2><p class="mb-4 text-xs text-gray-500">Subaccounts must use the same account type as their parent. Account creation is audit logged.</p>
            <form wire:submit="saveAccount" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <label class="grid content-start gap-1 text-xs font-semibold">Account number<input wire:model="number" placeholder="e.g. 1010" aria-describedby="account-number-help"><small id="account-number-help" class="font-normal text-gray-500">Letters, numbers, periods and hyphens.</small>@error('number')<small class="text-red-600">{{ $message }}</small>@enderror</label>
                <label class="grid content-start gap-1 text-xs font-semibold">Account name<input wire:model="name" placeholder="Account name">@error('name')<small class="text-red-600">{{ $message }}</small>@enderror</label>
                <label class="grid content-start gap-1 text-xs font-semibold">Account type<select wire:model.live="type">@foreach($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>@error('type')<small class="text-red-600">{{ $message }}</small>@enderror</label>
                <label class="grid content-start gap-1 text-xs font-semibold">Parent account<select wire:model="parentId"><option value="">Top-level account</option>@foreach($parentAccounts as $parent)<option value="{{ $parent->id }}">{{ $parent->number }} — {{ $parent->name }}</option>@endforeach</select>@error('parentId')<small class="text-red-600">{{ $message }}</small>@enderror</label>
                <div class="flex flex-col justify-between gap-3"><label class="flex items-center gap-2 text-xs"><input type="checkbox" wire:model="posting"><span>Posting account</span></label><button class="tpz-accounting-btn is-primary" type="submit">Create account</button></div>
                @error('bookId')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
            </form>
        </section>
    @endif
</x-accounting.workspace>
