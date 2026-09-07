<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ app(\App\Services\Branding\ApplicationBranding::class)->fullName() }} – Invoice Verification</title>
    <style>
        * { box-sizing: border-box; }
        body { align-items: center; background: #f4f7fb; color: #172033; display: flex; font-family: Arial, sans-serif; justify-content: center; margin: 0; min-height: 100vh; padding: 24px; }
        main { background: #fff; border: 1px solid #dbe3ec; border-radius: 14px; box-shadow: 0 12px 35px rgba(15, 23, 42, .08); max-width: 560px; padding: 30px; width: 100%; }
        h1 { color: #0b4478; font-size: 23px; margin: 0 0 4px; }
        .seller { color: #64748b; font-size: 14px; margin-bottom: 22px; }
        .state { border-radius: 8px; font-size: 15px; font-weight: 700; margin-bottom: 20px; padding: 12px; text-align: center; }
        .valid { background: #ecfdf5; color: #047857; }
        .void { background: #fef2f2; color: #b91c1c; }
        .invalid { background: #f8fafc; color: #475569; }
        dl { display: grid; gap: 11px; grid-template-columns: 140px 1fr; margin: 0; }
        dt { color: #64748b; }
        dd { font-weight: 600; margin: 0; overflow-wrap: anywhere; }
        @media (max-width: 480px) { dl { grid-template-columns: 1fr; gap: 4px; } dd { margin-bottom: 9px; } }
    </style>
</head>
<body>
<main>
    @if($invoice)
        @php($seller = $invoice->seller_snapshot)
        <h1>Invoice Verification</h1>
        <div class="seller">{{ $seller['company_name_en'] ?? 'Tech Point Zone' }}</div>
        <div class="state {{ $invoice->status === 'void' ? 'void' : 'valid' }}">
            {{ $invoice->status === 'void' ? 'VOID INVOICE' : 'VALID INVOICE' }}
        </div>
        <dl>
            <dt>Invoice</dt><dd>{{ $invoice->invoice_number }}</dd>
            <dt>Issue Date</dt><dd>{{ $invoice->invoice_date->format('d M Y') }}</dd>
            <dt>Order ID</dt><dd>{{ $invoice->order_reference ?: '—' }}</dd>
            <dt>Customer</dt><dd>{{ $invoice->customer_name }}</dd>
            <dt>Grand Total</dt><dd>AED {{ number_format((float) $invoice->grand_total, 2) }}</dd>
            <dt>Status</dt><dd>{{ $invoice->status === 'void' ? 'VOID' : 'VALID' }}</dd>
            <dt>Issued By</dt><dd>{{ $seller['company_name_en'] ?? '—' }}</dd>
            <dt>TRN</dt><dd>{{ $seller['trn'] ?? '—' }}</dd>
        </dl>
    @else
        <h1>Invoice Verification</h1>
        <div class="state invalid">Invoice could not be verified.</div>
    @endif
</main>
</body>
</html>
