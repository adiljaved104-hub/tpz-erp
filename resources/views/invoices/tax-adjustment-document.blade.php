<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $document->document_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #192b41; font-size: 11px; margin: 22px; }
        h1 { color: #142d52; font-size: 21px; margin: 0 0 10px; }
        h2 { font-size: 13px; margin: 14px 0 5px; }
        table { width: 100%; border-collapse: collapse; margin-top: 9px; }
        td, th { padding: 7px; border-bottom: 1px solid #dfe7ee; text-align: left; }
        th { background: #e9eef6; }
        .row { width: 100%; }
        .cell { width: 48%; display: inline-block; vertical-align: top; }
        .muted { color: #64748b; font-size: 10px; }
        .totals { margin-left: auto; width: 45%; }
        .totals td:last-child { text-align: right; }
    </style>
</head>
<body>
    <h1>{{ $document->kind === 'tax_credit_note' ? 'TAX CREDIT NOTE' : 'ADDITIONAL TAX INVOICE' }}</h1>
    <p><strong>Document:</strong> {{ $document->document_number }} &nbsp; <strong>Date:</strong> {{ $document->issued_on?->format('d M Y') }}</p>
    <p><strong>Original Tax Invoice:</strong> {{ $invoice->invoice_number }} &nbsp; <strong>Original Date:</strong> {{ $invoice->invoice_date?->format('d M Y') }}</p>

    <div class="row">
        <div class="cell">
            <h2>Supplier</h2>
            <div><strong>{{ $document->seller_snapshot['company_name_en'] ?? 'Supplier' }}</strong></div>
            <div>{{ $document->seller_snapshot['address_en'] ?? '' }}</div>
            <div>TRN: {{ $document->seller_snapshot['trn'] ?? '' }}</div>
        </div>
        <div class="cell">
            <h2>Customer</h2>
            <div><strong>{{ $document->customer_snapshot['name'] ?? '' }}</strong></div>
            <div>{{ $document->customer_snapshot['address'] ?? '' }}</div>
            @if(filled($document->customer_snapshot['trn'] ?? null))
                <div>TRN: {{ $document->customer_snapshot['trn'] }}</div>
            @endif
        </div>
    </div>

    <h2>Adjustment Details</h2>
    <p>{{ $document->explanation }}</p>
    <table>
        <thead><tr><th>Product / Supply</th><th>Qty</th><th>Original Unit AED</th><th>Correct Unit AED</th><th>Difference AED</th></tr></thead>
        <tbody>
        @foreach($document->lines_snapshot as $line)
            <tr>
                <td>{{ $line['description'] }}</td><td>{{ $line['quantity'] }}</td>
                <td>{{ number_format((float) $line['original_price'], 2) }}</td>
                <td>{{ number_format((float) $line['proposed_price'], 2) }}</td>
                <td>{{ number_format((float) $line['difference_gross'], 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <table class="totals">
        <tr><td>Original invoice supply value excl. VAT</td><td>AED {{ number_format((float) $document->original_invoice_net, 2) }}</td></tr>
        <tr><td>Adjusted supply value excl. VAT after this document</td><td>AED {{ number_format((float) $document->corrected_invoice_net, 2) }}</td></tr>
        <tr><td>Difference excl. VAT</td><td>AED {{ number_format((float) $document->net_amount, 2) }}</td></tr>
        <tr><td>{{ $document->kind === 'tax_credit_note' ? 'Output VAT reduction' : 'Additional output VAT' }}</td><td>AED {{ number_format((float) $document->vat_amount, 2) }}</td></tr>
        <tr><td><strong>{{ $document->kind === 'tax_credit_note' ? 'Credit Total' : 'Additional Amount Due' }}</strong></td><td><strong>AED {{ number_format((float) $document->gross_amount, 2) }}</strong></td></tr>
    </table>
    <p class="muted">This document relates exclusively to the original tax invoice identified above. The original tax invoice remains unchanged. Document reference {{ $document->document_number }}.</p>
</body>
</html>
