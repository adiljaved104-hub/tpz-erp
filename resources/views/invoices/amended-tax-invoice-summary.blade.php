<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Amended Invoice Summary — {{ $invoice->invoice_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #192b41; font-size: 11px; margin: 22px; }
        h1 { font-size: 21px; color: #16345a; margin-bottom: 6px; }
        h2 { font-size: 13px; margin: 15px 0 7px; }
        .banner { background: #fff0d5; padding: 12px; border: 1px solid #eab052; margin: 10px 0 18px; font-weight: bold; }
        .columns { width: 100%; }
        .column { display: inline-block; width: 48%; vertical-align: top; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        td, th { padding: 8px 6px; border-bottom: 1px solid #dce3ec; text-align: left; }
        th { background: #e9eef6; }
        .right { text-align: right; }
        .totals { width: 48%; margin-left: auto; }
        .muted { font-size: 10px; color: #64748b; }
    </style>
</head>
<body>
    <h1>AMENDED INVOICE SUMMARY</h1>
    <div class="banner">NOT A TAX INVOICE OR TAX CREDIT NOTE — Internal approved price review only.
        @unless($hasIssuedAdjustment) No official VAT adjustment document has been issued or posted. @endunless
        The original issued Tax Invoice remains unchanged.</div>
    <p><strong>Original Tax Invoice:</strong> {{ $invoice->invoice_number }}<br>
       <strong>Original invoice date:</strong> {{ $invoice->invoice_date?->format('d M Y') }}<br>
       <strong>Finance review:</strong> Request #{{ $proposal->id }} (approved {{ $proposal->decision?->decided_at?->format('d M Y') }})</p>
    <div class="columns">
        <div class="column">
            <h2>Supplier</h2>
            <strong>{{ $invoice->seller_snapshot['company_name_en'] ?? '' }}</strong><br>
            {{ $invoice->seller_snapshot['address_en'] ?? '' }}<br>
            TRN: {{ $invoice->seller_snapshot['trn'] ?? '' }}
        </div>
        <div class="column">
            <h2>Customer</h2>
            <strong>{{ $invoice->customer_name }}</strong><br>
            {{ $invoice->customer_address }}<br>
            @if(filled($invoice->customer_trn)) TRN: {{ $invoice->customer_trn }} @endif
        </div>
    </div>
    <h2>Reviewed Product Prices</h2>
    <table>
        <thead><tr><th>Product / Description</th><th class="right">Qty</th><th class="right">Original Unit AED</th><th class="right">Reviewed Unit AED</th><th class="right">Reviewed Total AED</th></tr></thead>
        <tbody>
        @foreach($proposal->proposed_line_snapshot as $index => $line)
            <tr>
                <td>{{ $line['description'] }}</td>
                <td class="right">{{ $line['quantity'] }}</td>
                <td class="right">{{ number_format((float) ($proposal->original_line_snapshot[$index]['unit_price_including_vat'] ?? 0), 2) }}</td>
                <td class="right">{{ number_format((float) $line['unit_price_including_vat'], 2) }}</td>
                <td class="right">{{ number_format((float) $line['gross'], 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @php
        $reviewedSubtotal = array_reduce($proposal->proposed_line_snapshot, fn ($sum, $line) => bcadd($sum, (string) $line['net'], 2), '0.00');
        $reviewedVat = array_reduce($proposal->proposed_line_snapshot, fn ($sum, $line) => bcadd($sum, (string) $line['vat'], 2), '0.00');
    @endphp
    <table class="totals">
        <tr><td>Original Invoice Total</td><td class="right">AED {{ number_format((float) $proposal->old_gross, 2) }}</td></tr>
        <tr><td>Reviewed Subtotal Excl. VAT</td><td class="right">AED {{ number_format((float) $reviewedSubtotal, 2) }}</td></tr>
        <tr><td>Reviewed VAT</td><td class="right">AED {{ number_format((float) $reviewedVat, 2) }}</td></tr>
        <tr><td><strong>Reviewed Grand Total</strong></td><td class="right"><strong>AED {{ number_format((float) $proposal->new_gross, 2) }}</strong></td></tr>
        <tr><td>Difference</td><td class="right">AED {{ number_format((float) $proposal->gross_difference, 2) }}</td></tr>
    </table>
    <h2>Correction Reason</h2>
    <p>{{ $proposal->reason }}</p>
    <p class="muted">This document displays an approved internal amendment proposal only. The original Tax Invoice and its financial records remain unchanged. For tax accounting and customer settlement, use only officially issued Tax Credit Notes or Additional Tax Invoices together with the original document.</p>
</body>
</html>
