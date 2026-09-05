<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number }}</title>
    <style>
        @page { size: A4 portrait; margin: 9mm 11mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #fff; color: #172033; font-family: "DejaVu Sans", sans-serif; font-size: 9px; line-height: 1.35; }
        table { border-collapse: collapse; width: 100%; }
        td, th { vertical-align: top; }
        .rule { border-top: 2px solid #d7e0ea; margin: 7px 0 9px; }
        .document-header td { vertical-align: middle; }
        .logo { max-height: 55px; max-width: 155px; }
        .title { color: #0b4478; font-size: 21px; font-weight: 700; letter-spacing: .5px; margin: 0; text-align: right; }
        .title-ar { color: #64748b; font-size: 13px; font-weight: 700; margin-top: 4px; text-align: right; }
        .details-shell { background: #f5f7fa; border-radius: 7px; margin-bottom: 10px; }
        .details-shell > tbody > tr > td { padding: 10px 12px; width: 50%; }
        .details-shell > tbody > tr > td + td { border-left: 1px solid #dbe3ec; }
        .section-label { border-bottom: 2px solid #dbe3ec; color: #0b4478; font-size: 8px; font-weight: 700; margin-bottom: 7px; padding-bottom: 4px; text-transform: uppercase; }
        .company-name { font-size: 11px; font-weight: 700; margin-bottom: 5px; }
        .arabic, .arabic-pdf { font-family: "DejaVu Sans", sans-serif; text-align: right; }
        .arabic { direction: rtl; unicode-bidi: embed; }
        .arabic-pdf { direction: ltr; unicode-bidi: bidi-override; }
        .arabic-line { color: #64748b; margin: 3px 0 5px; }
        .trn { color: #0b4478; font-weight: 700; margin-top: 7px; }
        .bill-to { text-align: right; }
        .invoice-details { margin-top: 17px; text-align: right; }
        .invoice-details div { margin: 2px 0; }
        .items { margin-bottom: 10px; table-layout: fixed; }
        .items thead { display: table-header-group; }
        .items tr { page-break-inside: avoid; }
        .items th { background: #0b4478; color: #fff; font-size: 8px; padding: 7px 6px; text-align: left; }
        .items th.num, .items td.num { text-align: right; }
        .items td { border-bottom: 1px solid #dbe3ec; padding: 7px 6px; }
        .items .description { font-weight: 600; }
        .summary-layout > tbody > tr > td { width: 56%; }
        .summary-layout > tbody > tr > td + td { padding-left: 12px; width: 44%; }
        .summary-layout { page-break-inside: avoid; }
        .closing-section.long-layout { page-break-inside: avoid; }
        .document-page.bottom-footer .footer { bottom: 0; left: 0; margin-top: 0; position: fixed; right: 0; }
        .terms { border-left: 3px solid #0b4478; background: #f7f9fb; padding: 9px 10px; }
        .terms-title { font-size: 9px; font-weight: 700; margin-bottom: 5px; }
        .term { margin-bottom: 5px; }
        .term-en { font-weight: 600; }
        .term-ar { color: #64748b; font-size: 8px; margin-top: 1px; }
        .totals { background: #f7f9fb; }
        .totals td { padding: 7px 9px; }
        .totals td:last-child { font-weight: 700; text-align: right; white-space: nowrap; }
        .grand td { border-top: 2px solid #c9d5e2; color: #0b4478; font-size: 13px; font-weight: 700; }
        .document-page { position: relative; }
        .footer-spacer { display: block; }
        .footer { color: #64748b; font-size: 7px; margin-top: 10px; text-align: center; }
        .footer { page-break-inside: avoid; }
        .stamp-area { margin-top: 9px; min-height: 72px; text-align: right; }
        .stamp { height: auto; max-height: 82px; max-width: 110px; width: auto; }
        .verification { margin-top: 9px; text-align: left; }
        .verification img { height: 76px; width: 76px; }
        .verification-caption { color: #334155; font-size: 7px; font-weight: 700; margin-bottom: 3px; }
        .verification-footer { color: #475569; font-size: 6.5px; line-height: 1.25; margin: 0 auto 5px; max-width: 100%; overflow-wrap: anywhere; padding: 0 4px; text-align: center; word-break: break-all; }
        .verification-footer a { color: #0b4478; text-decoration: none; }
        .company-footer { border-top: 1px solid #dbe3ec; padding-top: 6px; }
        .company-footer div { margin: 2px 0; }
        .footer strong { color: #334155; }
        .legal { font-size: 6.5px; margin-top: 5px !important; }
        .void { color: #b91c1c; font-size: 70px; font-weight: 700; left: 20%; opacity: .14; position: fixed; top: 42%; transform: rotate(-25deg); z-index: 10; }
        @media print {
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
@php
    $seller = $invoice->seller_snapshot;
    $isPdf = ($documentMode ?? 'pdf') === 'pdf';
    $ar = fn (?string $text): string => $isPdf ? $arabic->forDompdf($text) : (string) $text;
    $arClass = $isPdf ? 'arabic-pdf' : 'arabic';
    $termsEn = preg_split('/\R/u', trim((string) $invoice->terms_en_snapshot)) ?: [];
    $termsAr = preg_split('/\R/u', trim((string) $invoice->terms_ar_snapshot)) ?: [];
    $termCount = max(count($termsEn), count($termsAr));
    $vatDivisor = bcadd('1', bcdiv((string) $invoice->vat_rate, '100', 8), 8);
    $itemLayoutUnits = $invoice->items->sum(
        fn ($item): int => max(1, (int) ceil(mb_strlen((string) $item->description) / 72)),
    );
    $usesBottomFooter = $invoice->items->count() <= 3 && $itemLayoutUnits <= 3;
    $verificationHeightMm = ($qrCodeDataUri ?? null) ? 36 : 0;
    $footerSpacerMm = $usesBottomFooter ? 0 : max(0, 72 - (max(0, $itemLayoutUnits - 1) * 8) - $verificationHeightMm);
    $usesLongLayout = ! $usesBottomFooter && $footerSpacerMm === 0;
@endphp

<div class="document-page{{ $usesBottomFooter ? ' bottom-footer' : '' }}">

@if($invoice->status === 'void')
    <div class="void">VOID</div>
@endif

<table class="document-header">
    <tr>
        <td style="width: 50%">
            @if($logoDataUri)
                <img class="logo" src="{{ $logoDataUri }}" alt="Company logo">
            @endif
        </td>
        <td style="width: 50%">
            <div class="title">TAX INVOICE</div>
            <div class="title-ar {{ $arClass }}">{{ $ar('فاتورة ضريبية') }}</div>
        </td>
    </tr>
</table>
<div class="rule"></div>

<table class="details-shell">
    <tr>
        <td>
            <div class="section-label">FROM / <span class="{{ $arClass }}">{{ $ar('من') }}</span></div>
            <div class="company-name">{{ $seller['company_name_en'] }}</div>
            @if(filled($seller['company_name_ar'] ?? null))
                <div class="company-name {{ $arClass }}">{{ $ar($seller['company_name_ar']) }}</div>
            @endif
            @if(filled($seller['address_en'] ?? null))
                <div>{{ $seller['address_en'] }}</div>
            @endif
            @if(filled($seller['address_ar'] ?? null))
                <div class="arabic-line {{ $arClass }}">{{ $ar($seller['address_ar']) }}</div>
            @endif
            <div class="trn">TRN / <span class="{{ $arClass }}">{{ $ar('الرقم الضريبي') }}</span>: {{ $seller['trn'] ?? '—' }}</div>
        </td>
        <td class="bill-to">
            <div class="section-label">BILL TO / <span class="{{ $arClass }}">{{ $ar('إلى') }}</span></div>
            <div class="company-name">{{ $invoice->customer_name }}</div>
            <div>{{ $invoice->customer_address }}</div>
            @if(filled($invoice->customer_trn))
                <div class="trn">Customer TRN: {{ $invoice->customer_trn }}</div>
            @endif
            <div class="invoice-details">
                <div class="section-label">INVOICE DETAILS / <span class="{{ $arClass }}">{{ $ar('تفاصيل الفاتورة') }}</span></div>
                <div>Invoice #: <strong>{{ $invoice->invoice_number }}</strong></div>
                <div>Order ID: <strong>{{ $invoice->order_reference }}</strong></div>
                <div>Date: <strong>{{ $invoice->invoice_date->format('d M Y') }}</strong></div>
            </div>
        </td>
    </tr>
</table>

<table class="items">
    <thead>
    <tr>
        <th style="width: 5%">#</th>
        <th style="width: 52%">DESCRIPTION / <span class="{{ $arClass }}">{{ $ar('الوصف') }}</span></th>
        <th class="num" style="width: 8%">QTY</th>
        <th class="num" style="width: 16%">UNIT PRICE</th>
        <th class="num" style="width: 19%">TOTAL (INCL VAT)</th>
    </tr>
    </thead>
    <tbody>
    @foreach($invoice->items as $item)
        @php($unitExcludingVat = bcadd(bcdiv((string) $item->unit_price_including_vat, $vatDivisor, 4), '0.005', 2))
        <tr>
            <td>{{ $item->line_number }}</td>
            <td class="description">{{ $item->description }}</td>
            <td class="num">{{ $item->quantity }}</td>
            <td class="num">{{ number_format((float) $unitExcludingVat, 2) }}</td>
            <td class="num">{{ number_format((float) $item->total_including_vat, 2) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<div class="closing-section{{ $usesLongLayout ? ' long-layout' : '' }}">
<table class="summary-layout">
    <tr>
        <td>
            <div class="terms">
                <div class="terms-title">Terms &amp; Conditions (<span class="{{ $arClass }}">{{ $ar('الشروط والأحكام') }}</span>)</div>
                @for($index = 0; $index < $termCount; $index++)
                    <div class="term">
                        @if(filled($termsEn[$index] ?? null))
                            <div class="term-en">• {{ $termsEn[$index] }}</div>
                        @endif
                        @if(filled($termsAr[$index] ?? null))
                            <div class="term-ar {{ $arClass }}">{{ $ar($termsAr[$index]) }}</div>
                        @endif
                    </div>
                @endfor
            </div>
            @if($qrCodeDataUri ?? null)
                <div class="verification">
                    <div class="verification-caption">Verify Invoice Authenticity</div>
                    <img src="{{ $qrCodeDataUri }}" alt="Invoice verification QR code">
                </div>
            @endif
        </td>
        <td>
            <table class="totals">
                <tr>
                    <td>Subtotal / <span class="{{ $arClass }}">{{ $ar('المجموع الفرعي') }}</span></td>
                    <td>{{ number_format((float) $invoice->subtotal_excluding_vat, 2) }}</td>
                </tr>
                <tr>
                    <td>VAT ({{ number_format((float) $invoice->vat_rate, 0) }}%) / <span class="{{ $arClass }}">{{ $ar('ضريبة القيمة المضافة') }}</span></td>
                    <td>{{ number_format((float) $invoice->vat_amount, 2) }}</td>
                </tr>
                <tr class="grand">
                    <td>GRAND TOTAL (AED)</td>
                    <td>{{ number_format((float) $invoice->grand_total, 2) }}</td>
                </tr>
            </table>
            @if($stampDataUri ?? null)
                <div class="stamp-area"><img class="stamp" src="{{ $stampDataUri }}" alt="Company stamp"></div>
            @endif
        </td>
    </tr>
</table>

@if($footerSpacerMm > 0)
    <div class="footer-spacer" style="height: {{ $footerSpacerMm }}mm"></div>
@endif

<div class="footer">
    <div class="verification-footer">Verify this invoice: <a href="{{ $verificationUrl }}">{{ $verificationUrl }}</a></div>
    <div class="company-footer">
        <div><strong>Trade License No:</strong> {{ $seller['trade_license_number'] ?? '—' }} &nbsp; | &nbsp; <strong>DED Reg No:</strong> {{ $seller['ded_registration_number'] ?? '—' }} &nbsp; | &nbsp; <strong>TRN:</strong> {{ $seller['trn'] ?? '—' }}</div>
        <div><strong>Registered Address:</strong> {{ $seller['address_en'] ?? '—' }}</div>
        <div><strong>Phone:</strong> {{ $seller['phone'] ?? '—' }} &nbsp; | &nbsp; <strong>Mobile:</strong> {{ $seller['mobile'] ?? '—' }} &nbsp; | &nbsp; <strong>Email:</strong> {{ $seller['email'] ?? '—' }}</div>
        @if(filled($seller['legal_statement_en'] ?? null))
            <div class="legal">{{ $seller['legal_statement_en'] }}</div>
        @endif
        @if(filled($seller['legal_statement_ar'] ?? null))
            <div class="legal {{ $arClass }}">{{ $ar($seller['legal_statement_ar']) }}</div>
        @endif
    </div>
</div>

</div>

</div>

@if(($documentMode ?? null) === 'print')
    <script>window.addEventListener('load', () => window.print())</script>
@endif
</body>
</html>
