<x-accounting.workspace current="import" title="QuickBooks Import Center" description="Stage, map and reconcile historical exports before any approved migration.">
    <div class="space-y-5 tpz-accounting">

        @if($canManage)
            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <h2 class="text-base font-semibold">Start a read-only preview</h2>
                <p class="mt-1 max-w-3xl text-sm text-gray-500">Use a qbXML response or structured JSON/NDJSON/CSV export. Do not upload a QuickBooks company file. Only a fingerprint of the source label is retained for duplicate checks.</p>
                <form wire:submit="uploadAndPreview" class="mt-4 grid gap-4 md:grid-cols-3">
                    <label class="grid gap-1 text-sm"><span>Stable source-company label</span><x-filament::input.wrapper><x-filament::input type="text" wire:model="sourceCompanyIdentity" autocomplete="off" /></x-filament::input.wrapper>@error('sourceCompanyIdentity')<span class="text-danger-600">{{ $message }}</span>@enderror</label>
                    <label class="grid gap-1 text-sm"><span>qbXML / structured export</span><input type="file" wire:model="sourceFile" accept=".xml,.qbxml,.json,.jsonl,.ndjson,.csv" class="block w-full rounded-lg border border-gray-300 p-2 text-sm dark:border-white/10" />@error('sourceFile')<span class="text-danger-600">{{ $message }}</span>@enderror</label>
                    <div class="flex items-end"><x-filament::button type="submit" color="primary" icon="heroicon-m-eye">Checksum &amp; Preview</x-filament::button></div>
                </form>
                <div wire:loading wire:target="sourceFile,uploadAndPreview" class="mt-3 text-sm text-gray-500">Preparing private export preview…</div>
            </section>
        @endif

        <section class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900" wire:poll.10s>
            <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4 dark:border-white/10"><div><h2 class="font-semibold">Import batches</h2><p class="mt-1 text-sm text-gray-500">Dry-run history with resumable previews and row-level duplicate/change status.</p></div></div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm"><thead class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-white/5"><tr><th class="px-4 py-3">Batch</th><th class="px-4 py-3">Format</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Rows</th><th class="px-4 py-3">Duplicates</th><th class="px-4 py-3">Changed</th><th class="px-4 py-3">Invalid</th><th class="px-4 py-3">Checksum</th><th class="px-4 py-3">Started</th><th class="px-4 py-3"></th></tr></thead>
                    <tbody>
                        @forelse($batches as $batch)
                            <tr class="border-t border-gray-100 dark:border-white/5"><td class="px-4 py-3 font-mono">#{{ $batch->id }}</td><td class="px-4 py-3 uppercase">{{ $batch->source_format }}</td><td class="px-4 py-3"><span class="rounded-full bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 dark:bg-blue-400/10 dark:text-blue-300">{{ str_replace('_', ' ', $batch->status) }}</span></td><td class="px-4 py-3">{{ number_format($batch->record_count) }}</td><td class="px-4 py-3">{{ number_format($batch->duplicate_count) }}</td><td class="px-4 py-3">{{ number_format($batch->changed_count) }}</td><td class="px-4 py-3">{{ number_format($batch->invalid_count) }}</td><td class="px-4 py-3 font-mono text-xs">{{ substr($batch->file_sha256, 0, 12) }}…</td><td class="px-4 py-3">{{ $batch->created_at?->format('Y-m-d H:i') }}</td><td class="px-4 py-3">@if($canManage && in_array($batch->status, ['failed', 'uploaded'], true))<x-filament::button size="xs" color="gray" wire:click="resumePreview({{ $batch->id }})">Resume</x-filament::button>@endif</td></tr>
                            @if($batch->error_summary)<tr class="border-t border-amber-100 bg-amber-50 text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/5 dark:text-amber-200"><td colspan="10" class="px-4 py-2 text-xs">{{ implode(' ', $batch->error_summary) }}</td></tr>@endif
                        @empty
                            <tr><td colspan="10" class="px-4 py-10 text-center text-gray-500">No historical exports have been staged.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-gray-100 px-4 py-3 dark:border-white/10">{{ $batches->links() }}</div>
        </section>

        <aside class="rounded-xl border border-teal-200 bg-teal-50 p-4 text-sm text-teal-950 dark:border-teal-400/20 dark:bg-teal-400/5 dark:text-teal-100">
            <strong>Import boundary:</strong> these previews retain source data and relationships in private staging tables only. They do not create bills, payments, journals, GL entries or inventory movements. Currency and date fields are populated only for explicit recognized source fields; all other source fields remain in the preserved payload for later mapping and review.
        </aside>
    </div>
</x-accounting.workspace>
