<x-filament-panels::page>
    <form wire:submit="save" class="max-w-2xl space-y-6">
        <div class="grid gap-3 sm:grid-cols-3"><div class="rounded-xl border p-4"><small>Estimated today</small><div class="text-xl font-semibold">${{ number_format($spentToday, 4) }}</div></div><div class="rounded-xl border p-4"><small>Estimated this month</small><div class="text-xl font-semibold">${{ number_format($spentThisMonth, 4) }}</div></div><div class="rounded-xl border p-4"><small>Tokens this month</small><div class="text-xl font-semibold">{{ number_format($tokensThisMonth) }}</div></div></div>
        @if (($monthlyBudget > 0 && $spentThisMonth >= $monthlyBudget * 0.8) || ($dailyBudget > 0 && $spentToday >= $dailyBudget * 0.8))<div class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">AI Assistant usage has reached at least 80% of a configured budget.</div>@endif
        <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900 space-y-5">
            <label class="flex items-center gap-3"><input type="checkbox" wire:model="enabled" class="rounded border-gray-300 text-primary-600"><span class="font-medium">Enable AI Assistant</span></label>
            <p class="text-sm text-gray-500">Configure OPENAI_API_KEY on the server. The key is never stored in the database or sent to the browser.</p>
            <div><label class="text-sm font-medium">Provider</label><select wire:model="provider" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-950"><option value="mock">Mock (preview)</option><option value="openai">OpenAI Responses API</option></select></div>
            <div><label class="text-sm font-medium">Model</label><select wire:model="model" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-950"><option value="gpt-6-luna">GPT-6 Luna</option><option value="gpt-6.1-terra">GPT-6.1 Terra</option><option value="gpt-6.1-sol">GPT-6.1 Sol</option><option value="gpt-6-astra">GPT-6 Astra</option></select></div>
            <div class="grid gap-4 sm:grid-cols-2"><div><label class="text-sm font-medium">Daily budget (USD)</label><input type="number" min="0" step="0.01" wire:model="dailyBudget" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-950"></div><div><label class="text-sm font-medium">Monthly budget (USD)</label><input type="number" min="0" step="0.01" wire:model="monthlyBudget" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-950"></div></div>
            <p class="text-xs text-gray-500">The 80% threshold is recorded as an Owner-visible warning. Cost uses the configured model rate table.</p>
        </div>
        <x-filament::button type="submit">Save settings</x-filament::button>
    </form>
</x-filament-panels::page>
