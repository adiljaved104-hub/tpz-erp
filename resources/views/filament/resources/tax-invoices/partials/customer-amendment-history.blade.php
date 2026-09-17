<div class="space-y-4">
    @forelse ($amendments as $amendment)
        <article class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p class="text-sm font-semibold text-gray-950 dark:text-white">
                        {{ $amendment->created_at?->timezone(config('app.timezone'))->format('d M Y, h:i A') ?? 'Time not recorded' }}
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Amended by {{ $amendment->actor?->name ?? 'Unknown user' }}
                    </p>
                </div>
                <x-filament::badge color="gray">Customer details</x-filament::badge>
            </div>

            <div class="mt-3 rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/5">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Reason</p>
                <p class="mt-1 whitespace-pre-wrap break-words text-gray-950 dark:text-white">{{ $amendment->properties['reason'] ?? 'Not recorded' }}</p>
            </div>

            <div class="mt-3 grid gap-3 md:grid-cols-2">
                <div class="min-w-0 rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Previous</p>
                    <dl class="mt-2 space-y-2 text-sm">
                        <div><dt class="text-xs text-gray-500 dark:text-gray-400">Customer Name</dt><dd class="break-words text-gray-950 dark:text-white">{{ $amendment->properties['previous_customer_name'] ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-gray-500 dark:text-gray-400">Customer TRN</dt><dd class="break-words text-gray-950 dark:text-white">{{ $amendment->properties['previous_customer_trn'] ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-gray-500 dark:text-gray-400">Customer Address</dt><dd class="whitespace-pre-wrap break-words text-gray-950 dark:text-white">{{ $amendment->properties['previous_customer_address'] ?? '—' }}</dd></div>
                    </dl>
                </div>

                <div class="min-w-0 rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Updated</p>
                    <dl class="mt-2 space-y-2 text-sm">
                        <div><dt class="text-xs text-gray-500 dark:text-gray-400">Customer Name</dt><dd class="break-words text-gray-950 dark:text-white">{{ $amendment->properties['new_customer_name'] ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-gray-500 dark:text-gray-400">Customer TRN</dt><dd class="break-words text-gray-950 dark:text-white">{{ $amendment->properties['new_customer_trn'] ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-gray-500 dark:text-gray-400">Customer Address</dt><dd class="whitespace-pre-wrap break-words text-gray-950 dark:text-white">{{ $amendment->properties['new_customer_address'] ?? '—' }}</dd></div>
                    </dl>
                </div>
            </div>
        </article>
    @empty
        <p class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">No customer amendments have been recorded.</p>
    @endforelse
</div>
