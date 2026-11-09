<x-filament-panels::page>
    <x-filament::section heading="Trial Balance" description="Posted journal activity through the selected date." compact>
        <div class="mb-4 flex gap-3"><label class="grid gap-1 text-sm"><span>As of date</span><x-filament::input.wrapper><x-filament::input type="date" wire:model.live="asOfDate" /></x-filament::input.wrapper></label></div>
        <div class="overflow-x-auto"><table class="w-full min-w-[44rem] text-sm"><thead><tr class="text-left"><th>Account</th><th class="text-right">Debit</th><th class="text-right">Credit</th><th class="text-right">Balance</th></tr></thead><tbody>@forelse($rows as $row)<tr class="border-t border-gray-100 dark:border-white/5"><td>{{ $row['account']->number }} — {{ $row['account']->name }}</td><td class="text-right">AED {{ number_format((float)$row['debit'],2) }}</td><td class="text-right">AED {{ number_format((float)$row['credit'],2) }}</td><td class="text-right">AED {{ number_format((float)$row['balance'],2) }}</td></tr>@empty<tr><td colspan="4" class="py-8 text-center text-gray-500">No posted journals.</td></tr>@endforelse</tbody></table></div>
    </x-filament::section>
</x-filament-panels::page>
