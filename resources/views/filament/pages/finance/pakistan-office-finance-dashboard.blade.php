<x-filament-panels::page>
    <div class="grid min-w-0 gap-4" data-office-finance-dashboard>
        <x-filament::section compact>
            <div class="flex flex-wrap items-end gap-2">
                <x-filament::badge color="success">Currency: PKR</x-filament::badge>
                @foreach(['today'=>'Today','yesterday'=>'Yesterday','week'=>'This Week','month'=>'This Month','last_month'=>'Last Month','custom'=>'Custom'] as $value=>$label)
                    <x-filament::button size="xs" :color="$period === $value ? 'primary' : 'gray'" :outlined="$period !== $value" wire:click="setPeriod('{{ $value }}')">{{ $label }}</x-filament::button>
                @endforeach
                @if($period === 'custom')
                    <label class="grid gap-1 text-xs"><span>From</span><x-filament::input.wrapper><x-filament::input type="date" wire:model="from" /></x-filament::input.wrapper></label>
                    <label class="grid gap-1 text-xs"><span>To</span><x-filament::input.wrapper><x-filament::input type="date" wire:model="to" /></x-filament::input.wrapper></label>
                    <x-filament::button size="sm" wire:click="applyRange">Apply</x-filament::button>
                @endif
                <span class="ml-auto text-sm text-gray-500">{{ $rangeLabel }}</span>
            </div>
        </x-filament::section>

        <div class="grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach([
                ['funding_pkr','Funding Received','PKR',$metrics['can_funding']], ['funding_aed','Original Funding','AED',$metrics['can_funding']],
                ['effective_rate','Effective Rate','PKR/AED',$metrics['can_funding']], ['expenses','Total Expenses','PKR',$metrics['can_balances']],
                ['loans_given','Loans Given','PKR',$metrics['can_loans']], ['loan_repayments','Loan Repayments','PKR',$metrics['can_loans']],
                ['outstanding_loans','Outstanding Loans','PKR',$metrics['can_loans']], ['available_balance','Available Office Balance','PKR',$metrics['can_balances']]
            ] as [$key,$label,$currency,$visible])
                @if($visible)<x-filament::section compact><div class="text-xl font-bold">{{ $currency }} {{ number_format((float)($metrics['summary'][$key] ?? 0), $key === 'effective_rate' ? 6 : 2) }}</div><div class="mt-1 text-xs text-gray-500">{{ $label }}</div></x-filament::section>@endif
            @endforeach
        </div>

        @if($metrics['can_balances'])
            <x-filament::section heading="Account Balances" description="Balances are calculated from active ledger entries; they are never manually editable." compact>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">@forelse($metrics['accounts'] as $account)<div class="rounded-xl border border-gray-200 p-3 dark:border-white/10"><div class="font-semibold">{{ $account['name'] }}</div><div class="text-xs text-gray-500">{{ $account['type'] }} · {{ $account['active'] ? 'Active' : 'Inactive' }}</div><div class="mt-2 text-lg font-bold">PKR {{ number_format((float)$account['balance'],2) }}</div></div>@empty<div class="text-sm text-gray-500">Create an Office Account to begin.</div>@endforelse</div>
            </x-filament::section>
            <div class="grid min-w-0 gap-4 lg:grid-cols-2">
                @foreach(['expenses_by_category'=>'Expenses by Category','expenses_by_account'=>'Expenses by Account'] as $key=>$heading)
                    <x-filament::section :heading="$heading" compact><div class="overflow-x-auto"><table class="w-full min-w-[22rem] text-sm"><thead><tr><th class="py-2 text-left">{{ str($key)->contains('category') ? 'Category' : 'Account' }}</th><th class="py-2 text-right">Amount PKR</th></tr></thead><tbody>@forelse($metrics[$key] as $row)<tr class="border-t border-gray-100 dark:border-white/5"><td class="py-2">{{ str($row->label)->replace('_',' ')->title() }}</td><td class="py-2 text-right">{{ number_format((float)$row->amount,2) }}</td></tr>@empty<tr><td colspan="2" class="py-5 text-center text-gray-500">No expenses in this period.</td></tr>@endforelse</tbody></table></div></x-filament::section>
                @endforeach
            </div>
        @endif

        @if($metrics['can_funding'])
            <x-filament::section heading="Funding Analysis" compact><div class="overflow-x-auto"><table class="w-full min-w-[58rem] text-sm"><thead><tr><th>Date</th><th>AED Sent</th><th>Rate</th><th>Calculated PKR</th><th>Actual PKR</th><th>Difference</th><th>Account</th><th>Reference</th></tr></thead><tbody>@forelse($metrics['funding'] as $row)<tr class="border-t border-gray-100 dark:border-white/5"><td>{{ $row->transaction_date }}</td><td>{{ number_format((float)$row->aed_amount,2) }}</td><td>{{ number_format((float)$row->exchange_rate_pkr_per_aed,6) }}</td><td>{{ number_format((float)$row->calculated_pkr_amount,2) }}</td><td>{{ number_format((float)$row->amount_pkr,2) }}</td><td>{{ number_format((float)$row->difference,2) }}</td><td>{{ $row->account }}</td><td>{{ $row->external_reference ?: $row->reference }}</td></tr>@empty<tr><td colspan="8" class="py-5 text-center text-gray-500">No funding in this period.</td></tr>@endforelse</tbody></table></div></x-filament::section>
        @endif

        @if($metrics['can_loans'])
            <x-filament::section heading="Employee Loan Overview" compact><div class="overflow-x-auto"><table class="w-full min-w-[42rem] text-sm"><thead><tr><th>Employee</th><th>Reference</th><th class="text-right">Original</th><th class="text-right">Repaid</th><th class="text-right">Outstanding</th><th>Status</th></tr></thead><tbody>@forelse($metrics['loans'] as $row)<tr class="border-t border-gray-100 dark:border-white/5"><td>{{ $row->employee }}</td><td>{{ $row->reference }}</td><td class="text-right">PKR {{ number_format((float)$row->original_amount,2) }}</td><td class="text-right">PKR {{ number_format((float)$row->repaid,2) }}</td><td class="text-right">PKR {{ number_format((float)$row->outstanding,2) }}</td><td>{{ str($row->status)->replace('_',' ')->title() }}</td></tr>@empty<tr><td colspan="6" class="py-5 text-center text-gray-500">No employee loans.</td></tr>@endforelse</tbody></table></div></x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
