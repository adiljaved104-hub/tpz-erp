<div class="space-y-3">
    <p class="text-sm text-gray-600 dark:text-gray-300">Only issued documents with posted journals appear here. Open the PDF to provide the original official document to your customer in the approved delivery method.</p>
    @forelse($documents as $document)
        <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
            <div class="flex items-center justify-between gap-3">
                <strong>{{ $document->kind === 'tax_credit_note' ? 'Tax Credit Note' : 'Additional Tax Invoice' }}</strong>
                <span class="text-xs text-gray-500">{{ $document->issued_on?->format('Y-m-d') }}</span>
            </div>
            <div class="mt-1 text-sm">{{ $document->document_number }}</div>
            <div class="mt-2 text-sm">Net: AED {{ $document->net_amount }} · VAT: AED {{ $document->vat_amount }} · Gross: AED {{ $document->gross_amount }}</div>
            <div class="mt-2 text-xs text-gray-600 dark:text-gray-300">Accounting journal #{{ $document->adjustment_accounting_journal_id }} · Reconciliation: {{ $document->financial_reconciliation_reference }}</div>
            <a class="mt-3 inline-block text-sm font-semibold text-primary-600 underline" href="{{ route('tax-invoices.adjustment-document.pdf', ['document' => $document]) }}" target="_blank" rel="noopener noreferrer">Download Official PDF</a>
        </div>
    @empty
        <p class="text-sm text-gray-500">No official adjustment documents have been issued.</p>
    @endforelse
</div>
