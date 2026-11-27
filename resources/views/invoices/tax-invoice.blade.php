<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number }}</title>
    <style>
        @page { size: A4 portrait; margin: 9mm 10.3mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #fff; color: #282723; font-family: "DejaVu Sans", sans-serif; font-size: 8pt; line-height: 1.35; }
        table { border-collapse: collapse; width: 100%; }
        td, th { vertical-align: top; }
        .arabic, .arabic-pdf { font-family: "DejaVu Sans", sans-serif; text-align: right; white-space: pre-line; }
        .arabic { direction: rtl; unicode-bidi: embed; }
        .arabic-pdf { direction: ltr; unicode-bidi: bidi-override; }
        .document-header td { vertical-align: middle; padding-bottom: 5px; }
        .logo { max-height: 65pt; max-width: 100pt; }
        .company-name { font-size: 15pt; font-weight: bold; line-height: 1.2; }
        .company-name-ar { font-size: 9pt; margin-top: 5px; }
        .title { color: #ad915d; font-size: 22pt; font-weight: bold; text-align: right; line-height: 1.1; white-space: nowrap; }
        .title-ar { color: #ad915d; font-size: 17pt; font-weight: bold; margin-top: 7px; }
        .seller-details { margin-top: 7px; }
        .seller-details td { width: 50%; padding: 2px 0; }
        .seller-details .right { text-align: right; padding-left: 12px; }
        .address { white-space: pre-line; }
        .registration td { padding-top: 3px; }
        .registration .value { font-weight: bold; text-align: right; }
        .contact-label { color: #58554f; display: inline-block; width: 46px; }
        .rule { border-top: 1.2px solid #ad915d; margin: 7px 0 12px; }
        .details-shell { table-layout: fixed; margin-bottom: 10px; }
        .details-shell > tbody > tr > td { width: 50%; }
        .details-shell > tbody > tr > td:first-child { padding-right: 8px; }
        .details-shell > tbody > tr > td:last-child { padding-left: 8px; }
        .section-label { color: #ad915d; font-size: 9pt; font-weight: bold; margin-bottom: 7px; }
        .fields { table-layout: fixed; }
        .fields td { padding: 4px 1px; }
        .fields .label { color: #58554f; width: 30%; }
        .fields .value { border-bottom: .5px solid #ded5c3; width: 70%; overflow-wrap: break-word; }
        .items-heading { margin: 7px 0 4px; }
        .items-heading td:last-child { text-align: right; color: #58554f; }
        .items { margin-bottom: 4px; table-layout: fixed; }
        .items thead { display: table-header-group; }
        .items tr { page-break-inside: avoid; }
        .items th { background: #f8f5ee; border-top: .6px solid #ad915d; border-bottom: .6px solid #cbb992; padding: 5px 3px; font-size: 7pt; text-align: right; }
        .items th:first-child { text-align: left; color: #ad915d; }
        .items th .sub { color: #58554f; font-weight: normal; font-size: 6.5pt; margin-top: 2px; }
        .items td { padding: 6px 3px; border-bottom: .5px solid #ded5c3; overflow-wrap: break-word; }
        .items .num { text-align: right; }
        .items .description { white-space: pre-line; }
        .items .empty td { height: 12px; padding: 0; font-size: 1pt; line-height: 1; }
        .line-note { font-size: 6.5pt; color: #58554f; margin: 4px 0 7px; }
        .summary-layout { page-break-inside: avoid; }
        .summary-layout > tbody > tr > td:first-child { width: 47%; padding-right: 12px; }
        .summary-layout > tbody > tr > td:last-child { width: 53%; }
        .totals td { padding: 3px 2px; }
        .totals td:last-child { text-align: right; white-space: nowrap; font-weight: bold; }
        .totals .grand td { border-top: 1px solid #ad915d; border-bottom: 1px solid #ad915d; font-weight: bold; color: #ad915d; }
        .totals .balance td { font-weight: bold; }
        .summary-ar { text-align: right; color: #58554f; font-size: 7pt; }
        .payment-note { font-size: 6.5pt; color: #58554f; margin-top: 4px; }
        .verification { display: inline-block; vertical-align: top; text-align: left; margin-right: 16px; }
        .verification img { height: 76px; width: 76px; }
        .verification-caption { font-size: 6.5pt; margin-bottom: 3px; }
        .stamp-area { display: inline-block; vertical-align: middle; text-align: right; padding-top: 10px; }
        .stamp { height: auto; max-height: 82px; max-width: 110px; width: auto; }
        .document-page { position: relative; }
        .document-page.bottom-footer .footer:not(.has-branding) { page-break-inside: avoid; }
        .closing-section.long-layout { page-break-inside: avoid; }
        .footer { margin-top: 7px; font-size: 7pt; color: #58554f; }
        .terms { border-top: 1px solid #ad915d; padding-top: 3px; page-break-inside: auto; }
        .terms-title { font-size: 8.5pt; color: #ad915d; font-weight: bold; }
        .terms-table { table-layout: fixed; page-break-inside: auto; }
        .terms-table tr { page-break-inside: avoid; }
        .terms-table td { width: 50%; padding: 1px 8px 1px 0; }
        .terms-table td:last-child { padding: 1px 0 1px 8px; }
        .term-en { white-space: normal; line-height: 1.18; }
        .terms-independent-row > td { vertical-align: top; }
        .term-paragraph { margin: 0 0 2pt; line-height: 1.18; }
        .term-paragraph:last-child { margin-bottom: 0; }
        .term-ar { font-size: 6.8pt; line-height: 1.18; }
        .extended-terms .terms-table td { padding-top: 1px; padding-bottom: 1px; }
        .extended-terms .term-en, .extended-terms .term-ar { font-size: 6.5pt; line-height: 1.17; }
        .company-footer { border-top: .6px solid #cbb992; margin-top: 6px; padding-top: 5px; font-size: 6.5pt; }
        .company-footer td { width: 50%; }
        .verification-footer { margin-top: 4px; font-size: 6pt; overflow-wrap: anywhere; word-break: break-all; text-align: center; }
        .verification-footer a { color: #58554f; text-decoration: none; }
        .customer-copy { text-align: right; font-size: 7pt; margin-top: 4px; font-weight: bold; }
        .branding-strip { border-top: .6px solid #ad915d; margin-top: 4px; page-break-inside: avoid; }
        .branding-logos { table-layout: fixed; }
        .branding-logos td { text-align: center; padding: 3px 2px 2px; vertical-align: top; }
        .brand-art { height: 22pt; line-height: 22pt; }
        .brand-art img { max-width: 55pt; max-height: 21pt; vertical-align: middle; }
        .brand-caption { font-size: 6pt; line-height: 1.18; overflow-wrap: break-word; margin-top: 2px; }
        .branding-statement { border-top: .6px solid #cbb992; margin-top: 4px; font-size: 6pt; table-layout: fixed; }
        .branding-statement td { width: 50%; padding: 4px 4px 0 0; overflow-wrap: break-word; }
        .branding-statement td:last-child { padding-left: 4px; padding-right: 0; }
        .footer.has-branding { page-break-inside: auto; }
        .document-page.compact .seller-details { margin-top: 4px; }
        .document-page.compact .seller-details td { padding: 1px 0; }
        .document-page.compact .rule { margin: 5px 0 8px; }
        .document-page.compact .details-shell { margin-bottom: 6px; }
        .document-page.compact .fields td { padding: 3px 1px; }
        .document-page.compact .items td { padding: 4px 3px; }
        .has-branding .terms-table td { padding-top: 0; padding-bottom: 1px; }
        .has-branding .term-en, .has-branding .term-ar { font-size: 6.5pt; line-height: 1.16; }
        .void { color: #b91c1c; font-size: 70pt; font-weight: bold; left: 20%; opacity: .14; position: fixed; top: 42%; transform: rotate(-25deg); z-index: 10; }
        @media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style>
</head>
<body>
@php
    $seller = $invoice->seller_snapshot;
    $isPdf = ($documentMode ?? 'pdf') === 'pdf';
    $ar = function (?string $text, int $width = 62) use ($isPdf, $arabic): string {
        if (! $isPdf) { return (string) $text; }
        $lines = [];
        foreach (preg_split('/\R/u', (string) $text) ?: [] as $paragraph) {
            $line = '';
            foreach (preg_split('/\s+/u', $paragraph) ?: [] as $word) {
                if ($line !== '' && mb_strlen($line.' '.$word) > $width) { $lines[] = $line; $line = ''; }
                $line .= ($line === '' ? '' : ' ').$word;
            }
            $lines[] = $line;
        }
        return $arabic->forDompdf(implode("\n", $lines));
    };
    $arClass = $isPdf ? 'arabic-pdf' : 'arabic';
    // Terms are immutable at issuance. Collapse blank/whitespace-only lines only
    // for display: independent English and Arabic paragraphs must not create
    // empty table rows when the translations have different line breaks.
    $termLines = fn (?string $text): array => array_values(array_filter(
        array_map('trim', preg_split('/\R/u', trim((string) $text)) ?: []),
        fn (string $line): bool => $line !== ''
    ));
    $termsEn = $termLines($invoice->terms_en_snapshot);
    $termsAr = $termLines($invoice->terms_ar_snapshot);
    $renewedTermsEn = $termLines($invoice->renewed_terms_en_snapshot);
    $renewedTermsAr = $termLines($invoice->renewed_terms_ar_snapshot);
    $termsLayoutUnits = collect([...$termsEn, ...$termsAr, ...$renewedTermsEn, ...$renewedTermsAr])
        ->sum(fn ($line): int => filled($line) ? max(1, (int) ceil(mb_strlen($line) / 50)) : 0);
    $usesExtendedTerms = $termsLayoutUnits > 24;
    $englishTermsLength = mb_strlen(implode('', [...$termsEn, ...$renewedTermsEn]));
    $arabicTermsLength = mb_strlen(implode('', [...$termsAr, ...$renewedTermsAr]));
    $termsEnglishWidth = $usesExtendedTerms && $englishTermsLength > max(1, $arabicTermsLength) * 3 ? 75
        : ($usesExtendedTerms && $arabicTermsLength > max(1, $englishTermsLength) * 3 ? 25 : 50);
    $itemLayoutUnits = $invoice->items->sum(fn ($item): int => max(1, (int) ceil(mb_strlen((string) $item->description) / 60)));
    $usesBottomFooter = $invoice->items->count() <= 3 && $itemLayoutUnits <= 5 && ! $usesExtendedTerms;
    $usesLongLayout = ! $usesBottomFooter && ! $usesExtendedTerms;
    $usesCompactLayout = $invoice->items->count() <= 3 && $itemLayoutUnits <= 9
        && count($footerBranding['entries'] ?? []) > 0;
    $context = $orderContext ?? ['heading' => 'ORDER ID', 'reference' => $invoice->order_reference];
    $blankRowTarget = count($footerBranding['entries'] ?? []) > 0 ? 0 : 8;
    // Only a previously confirmed, immutable issuance snapshot may show paid.
    $paymentEvent = $paymentEvent ?? null;
    $paymentSnapshot = $seller['payment_receipt_snapshot'] ?? [];
    $paymentConfirmed = is_array($paymentSnapshot)
        && ($paymentSnapshot['status'] ?? null) === 'confirmed_paid_in_full'
        && (string) ($paymentSnapshot['amount_received_aed'] ?? '') === (string) $invoice->grand_total
        && (string) ($paymentSnapshot['balance_due_aed'] ?? '') === '0.00';
    $currentPaymentStatus = $paymentEvent?->status ?? ($paymentConfirmed ? 'paid' : 'unverified');
    $paidInFull = $currentPaymentStatus === 'paid'
        && ($paymentEvent === null || (string) $paymentEvent->payment_received_aed === (string) $invoice->grand_total);
    $confirmedUnpaid = $currentPaymentStatus === 'unpaid'
        && $paymentEvent !== null && (string) $paymentEvent->balance_due_aed === (string) $invoice->grand_total;
@endphp
<div class="document-page{{ $usesBottomFooter ? ' bottom-footer' : '' }}{{ $usesCompactLayout ? ' compact' : '' }}">
@if($invoice->status === 'void')<div class="void">VOID</div>@endif
<table class="document-header">
    <tr>
        <td style="width: 22%">@if($logoDataUri)<img class="logo" src="{{ $logoDataUri }}" alt="Company logo">@endif</td>
        <td style="width: 46%">
            <div class="company-name">{{ $seller['company_name_en'] ?? '' }}</div>
            @if(filled($seller['company_name_ar'] ?? null))<div class="company-name-ar {{ $arClass }}">{{ $ar($seller['company_name_ar'], 54) }}</div>@endif
        </td>
        <td style="width: 32%"><div class="title">TAX INVOICE</div><div class="title-ar {{ $arClass }}">{{ $ar('فاتورة ضريبية') }}</div></td>
    </tr>
</table>
<table class="seller-details">
    <tr>
        <td>
            @if(filled($seller['address_en'] ?? null))<div class="address">{{ $seller['address_en'] }}</div>@endif
            @if(filled($seller['emirate'] ?? null) || filled($seller['country'] ?? null))<div>{{ implode(', ', array_filter([$seller['emirate'] ?? null, $seller['country'] ?? null])) }}</div>@endif
            @foreach(['email' => 'Email', 'mobile' => 'Mobile', 'phone' => 'Phone', 'website' => 'Website'] as $field => $label)
                @if(filled($seller[$field] ?? null))<div><span class="contact-label">{{ $label }}</span> <strong>{{ $seller[$field] }}</strong></div>@endif
            @endforeach
        </td>
        <td class="right">
            @if(filled($seller['address_ar'] ?? null))<div class="address {{ $arClass }}">{{ $ar($seller['address_ar']) }}</div>@endif
            <table class="registration">
                @foreach(['trn' => ['VAT TRN:', 'رقم التسجيل الضريبي'], 'trade_license_number' => ['Trade License No:', 'الرخصة التجارية'], 'ded_registration_number' => ['DED Reg No:', 'السجل التجاري']] as $field => [$label, $arabicLabel])
                    @if(filled($seller[$field] ?? null))<tr><td>{{ $label }} <span class="{{ $arClass }}">{{ $ar($arabicLabel) }}</span></td><td class="value">{{ $seller[$field] }}</td></tr>@endif
                @endforeach
            </table>
        </td>
    </tr>
</table>
<div class="rule"></div>
<table class="details-shell">
    <tr>
        <td>
            <div class="section-label">BILL TO <span class="{{ $arClass }}">{{ $ar('المستلم') }}</span></div>
            <table class="fields">
                <tr><td class="label">Customer</td><td class="value">{{ $invoice->customer_name }}</td></tr>
                <tr><td class="label">Billing address</td><td class="value address">{{ $invoice->customer_address }}</td></tr>
                @if(filled($invoice->customer_phone))<tr><td class="label">Phone:</td><td class="value">{{ $invoice->customer_phone }}</td></tr>@endif
                @if(filled($invoice->customer_trn))<tr><td colspan="2" class="value">Customer TRN: {{ $invoice->customer_trn }}</td></tr>@endif
            </table>
        </td>
        <td>
            <div class="section-label">{{ $context['heading'] }} <span class="{{ $arClass }}">{{ $ar($context['headingAr'] ?? 'رقم الطلب') }}</span></div>
            <table class="fields">
                <tr><td class="label">Invoice #:</td><td class="value"><strong>{{ $invoice->invoice_number }}</strong></td></tr>
                <tr><td class="label">{{ $context['referenceLabel'] ?? 'Order ID' }}</td><td class="value">{{ $context['reference'] }}</td></tr>
                <tr><td class="label">Invoice date</td><td class="value">{{ $invoice->invoice_date->format('d M Y') }}</td></tr>
                <tr><td class="label">Supply date</td><td class="value">Not recorded</td></tr>
            </table>
        </td>
    </tr>
</table>
<table class="items-heading"><tr><td class="section-label">ITEMS <span class="{{ $arClass }}">{{ $ar('الأصناف') }}</span></td><td>Unit prices include {{ number_format((float) $invoice->vat_rate, 2) }}% VAT &nbsp; • &nbsp; AED</td></tr></table>
<table class="items">
    <thead><tr>
        <th style="width: 42%">Description / Serial Number<div class="sub {{ $arClass }}">{{ $ar('الوصف / الرقم التسلسلي') }}</div></th>
        <th style="width: 6%">Qty<div class="sub {{ $arClass }}">{{ $ar('الكمية') }}</div></th>
        <th style="width: 14%">Unit incl. VAT<div class="sub {{ $arClass }}">{{ $ar('سعر الوحدة') }}</div></th>
        <th style="width: 10%">VAT rate (%)<div class="sub {{ $arClass }}">{{ $ar('نسبة الضريبة') }}</div></th>
        <th style="width: 12%">VAT (AED)<div class="sub {{ $arClass }}">{{ $ar('مبلغ الضريبة') }}</div></th>
        <th style="width: 16%">Total incl. VAT<div class="sub {{ $arClass }}">{{ $ar('شامل الضريبة') }}</div></th>
    </tr></thead>
    <tbody>
    @foreach($invoice->items as $item)
        <tr>
            <td class="description{{ preg_match('/\p{Arabic}/u', $item->description) ? ' '.$arClass : '' }}">{{ preg_match('/\p{Arabic}/u', $item->description) ? $ar($item->description, 55) : $item->description }}</td>
            <td class="num">{{ $item->quantity }}</td>
            <td class="num">{{ number_format((float) $item->unit_price_including_vat, 2) }}</td>
            <td class="num">{{ number_format((float) $invoice->vat_rate, 2) }}</td>
            <td class="num">{{ number_format((float) $item->vat_amount, 2) }}</td>
            <td class="num">{{ number_format((float) $item->total_including_vat, 2) }}</td>
        </tr>
    @endforeach
    @if($usesBottomFooter)
        @for($empty = $invoice->items->count(); $empty < $blankRowTarget; $empty++)<tr class="empty"><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td></tr>@endfor
    @endif
    </tbody>
</table>
<div class="line-note">Line totals include any discount. Unit prices are rounded to the nearest fils.</div>
<div class="closing-section{{ $usesLongLayout ? ' long-layout' : '' }}">
<table class="summary-layout">
    <tr>
        <td>
            @if($qrCodeDataUri ?? null)<div class="verification"><div class="verification-caption">Verify Invoice Authenticity</div><img src="{{ $qrCodeDataUri }}" alt="Invoice verification QR code"></div>@endif
            @if($stampDataUri ?? null)<div class="stamp-area"><img class="stamp" src="{{ $stampDataUri }}" alt="Company stamp"></div>@endif
        </td>
        <td>
            <table class="totals">
                <tr><td>Subtotal (excl. VAT)</td><td class="summary-ar {{ $arClass }}">{{ $ar('المجموع الفرعي') }}</td><td>{{ number_format((float) $invoice->subtotal_excluding_vat, 2) }}</td></tr>
                <tr><td>Total VAT</td><td class="summary-ar {{ $arClass }}">{{ $ar('إجمالي الضريبة') }}</td><td>{{ number_format((float) $invoice->vat_amount, 2) }}</td></tr>
                <tr class="grand"><td>Invoice total (AED)</td><td class="summary-ar {{ $arClass }}">{{ $ar('إجمالي الفاتورة') }}</td><td>{{ number_format((float) $invoice->grand_total, 2) }}</td></tr>
                <tr><td>Payment received</td><td class="summary-ar {{ $arClass }}">{{ $ar('المبلغ المدفوع') }}</td><td>{{ $paidInFull ? number_format((float) $invoice->grand_total, 2) : ($confirmedUnpaid ? '0.00' : 'Not recorded') }}</td></tr>
                <tr class="balance"><td>Balance due</td><td class="summary-ar {{ $arClass }}">{{ $ar('الرصيد المستحق') }}</td><td>{{ $paidInFull ? '0.00' : ($confirmedUnpaid ? number_format((float) $invoice->grand_total, 2) : 'Not verified') }}</td></tr>
            </table>
            @if($paymentEvent !== null && $paymentEvent->source === 'manual_status')
                <div class="payment-note">Payment status confirmed as {{ strtoupper($currentPaymentStatus) }} on {{ $paymentEvent->created_at?->format('d M Y') }}. Invoice amounts, VAT and original issuance records are unchanged. This is not an accounting receipt.</div>
            @elseif($paidInFull)
                <div class="payment-note">Full customer payment was confirmed when this invoice was issued. No separate payment or accounting journal is created by this document.</div>
            @elseif($confirmedUnpaid)
                <div class="payment-note">Customer payment was confirmed not received. This is a payment-status record, not an accounting posting.</div>
            @else
                <div class="payment-note">Customer payment is not recorded on this invoice. These labels do not assert an unpaid balance.</div>
            @endif
        </td>
    </tr>
</table>
</div>
<div class="footer{{ count($footerBranding['entries'] ?? []) > 0 ? ' has-branding' : '' }}">
    @if($usesExtendedTerms)<div class="extended-terms">@include('invoices.partials.tax-invoice-terms')</div>@else
        @include('invoices.partials.tax-invoice-terms')
    @endif
    @include('invoices.partials.tax-invoice-branding')
    <div class="company-footer"><table><tr>
        <td>@if(filled($seller['legal_statement_en'] ?? null))<div class="legal">{{ $seller['legal_statement_en'] }}</div>@endif</td>
        <td>@if(filled($seller['legal_statement_ar'] ?? null))<div class="legal {{ $arClass }}">{{ $ar($seller['legal_statement_ar']) }}</div>@endif</td>
    </tr></table></div>
    <div class="verification-footer">Verify this invoice: <a href="{{ $verificationUrl }}">{{ $verificationUrl }}</a></div>
    <div class="customer-copy">Customer copy &nbsp; <span class="{{ $arClass }}">{{ $ar('نسخة العميل') }}</span></div>
</div>
</div>
@if(($documentMode ?? null) === 'print')<script>window.addEventListener('load', () => window.print())</script>@endif
</body>
</html>
