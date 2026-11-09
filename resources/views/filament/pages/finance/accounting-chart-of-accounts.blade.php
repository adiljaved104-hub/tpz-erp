<x-filament-panels::page>
    <div class="grid gap-4">
        <x-filament::section heading="UAE Chart of Accounts" description="AED general ledger accounts. Posting remains disabled until the Owner enables this book." compact>
            <form wire:submit="saveAccount" class="grid gap-3 md:grid-cols-4">
                <label class="grid gap-1 text-sm"><span>Book</span><x-filament::input.wrapper><x-filament::input.select wire:model="bookId">@foreach($books as $item)<option value="{{ $item->id }}">{{ $item->name }} ({{ $item->currency }})</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                <label class="grid gap-1 text-sm"><span>Account number</span><x-filament::input.wrapper><x-filament::input wire:model="number" /></x-filament::input.wrapper></label>
                <label class="grid gap-1 text-sm"><span>Name</span><x-filament::input.wrapper><x-filament::input wire:model="name" /></x-filament::input.wrapper></label>
                <label class="grid gap-1 text-sm"><span>Type</span><x-filament::input.wrapper><x-filament::input.select wire:model="type">@foreach($types as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="posting" class="rounded"> Posting account</label>
                <div class="flex justify-end md:col-span-3"><x-filament::button type="submit">Create account</x-filament::button></div>
            </form>
        </x-filament::section>
        <x-filament::section heading="Accounts" compact>
            <div class="overflow-x-auto"><table class="w-full min-w-[48rem] text-sm"><thead><tr class="text-left"><th>Number</th><th>Name</th><th>Type</th><th>Normal balance</th><th>Status</th></tr></thead><tbody>@forelse($accounts as $account)<tr class="border-t border-gray-100 dark:border-white/5"><td>{{ $account->number }}</td><td>{{ $account->name }}</td><td>{{ ucfirst($account->account_type->value) }}</td><td>{{ ucfirst($account->normal_balance) }}</td><td>{{ $account->active ? ($account->is_posting ? 'Posting' : 'Header') : 'Inactive' }}</td></tr>@empty<tr><td colspan="5" class="py-8 text-center text-gray-500">No accounts.</td></tr>@endforelse</tbody></table></div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
