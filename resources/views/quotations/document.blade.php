<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $quotation->reference }}</title>
    <style>
        @page { size: A4 portrait; margin: 9mm 11mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #fff; color: #262626; font-family: "DejaVu Sans", sans-serif; font-size: 8pt; line-height: 1.28; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }
        .arabic, .arabic-pdf { font-family: "DejaVu Sans", sans-serif; text-align: right; }
        .arabic { direction: rtl; unicode-bidi: embed; }
        .arabic-pdf { direction: ltr; unicode-bidi: bidi-override; }
        .document-page { position: relative; }
        .document-header td { vertical-align: top; }
        .logo { max-width: 100pt; max-height: 45pt; }
        .company-name { font-size: 14pt; font-weight: bold; line-height: 1.16; }
        .company-name-ar { font-size: 7pt; margin-top: 5pt; color: #595751; }
        .title { color: #ad915d; font-size: 18pt; font-weight: bold; letter-spacing: .4pt; text-align: right; }
        .title-ar { color: #ad915d; font-size: 12pt; font-weight: bold; margin-top: 5pt; }
        .non-tax { font-size: 6pt; color: #725d3b; margin-top: 5pt; text-align: right; }
        .seller-details { margin-top: 6pt; font-size: 7.2pt; }
        .seller-details td { width: 50%; }
        .seller-details td.right { text-align: right; }
        .address { white-space: pre-line; overflow-wrap: break-word; }
        .contact-label { display: inline-block; width: 46pt; color: #595751; }
        .registration { margin-top: 5pt; }
        .registration td { padding: 1pt 0; font-size: 6.8pt; }
        .registration .value { text-align: right; font-weight: bold; white-space: nowrap; }
        .rule { border-top: .6pt solid #ad915d; margin: 5pt 0 8pt; }
        .details-shell { table-layout: fixed; margin-bottom: 7pt; }
        .details-shell > tbody > tr > td { width: 50%; padding: 0 8pt 0 0; }
        .details-shell > tbody > tr > td:last-child { padding: 0 0 0 8pt; }
        .section-label { color: #ad915d; font-weight: bold; font-size: 8pt; margin-bottom: 5pt; text-transform: uppercase; }
        .fields { table-layout: fixed; }
        .fields td { border-bottom: .45pt solid #dad7d0; padding: 3pt 1pt; font-size: 7pt; }
        .fields .label { color: #5b5954; width: 34%; }
        .fields .value { width: 66%; overflow-wrap: break-word; white-space: pre-line; }
        .invoice-trn { direction: ltr; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .items-heading { color: #ad915d; font-weight: bold; font-size: 8pt; margin: 5pt 0 4pt; }
        .items { table-layout: fixed; margin-bottom: 0; }
        .items thead { display: table-header-group; }
        .items tr { page-break-inside: avoid; }
        .items th { padding: 6pt 2pt; background: #f7f3eb; border-top: .6pt solid #ad915d; border-bottom: .6pt solid #c4b38f; font-size: 6.7pt; text-align: right; }
        .items th:first-child { text-align: left; }
        .items .th-ar { font-size: 5.8pt; color: #62594a; display: block; margin-top: 2pt; }
        .items td { padding: 4pt 3pt; border-bottom: .45pt solid #e0dcd3; font-size: 6.7pt; }
        .items td.num { text-align: right; white-space: nowrap; }
        .sku { color: #686561; font-size: 6pt; margin-top: 2pt; }
        .closing { margin-top: 4pt; page-break-inside: avoid; }
        .closing > tbody > tr > td { width: 48%; vertical-align: top; }
        .closing > tbody > tr > td:last-child { width: 52%; padding-left: 8pt; }
        .stamp { max-width: 90pt; max-height: 75pt; }
        .stamp-area { margin: 2pt 0; }
        .summary td { padding: 4pt 0; border-bottom: .45pt solid #ddd8cd; font-size: 7pt; }
        .summary td:first-child { width: 48%; }
        .summary td:nth-child(2) { width: 30%; text-align: right; font-size: 6.2pt; color: #656057; }
        .summary td:last-child { width: 22%; text-align: right; font-weight: bold; white-space: nowrap; }
        .summary .grand td { color: #ad915d; font-weight: bold; border-top: .6pt solid #ad915d; font-size: 8pt; }
        .terms { border-top: .6pt solid #ad915d; padding-top: 4pt; margin-top: 8pt; page-break-inside: auto; }
        .terms-title { color: #ad915d; font-weight: bold; font-size: 7.8pt; }
        .terms-table { table-layout: fixed; page-break-inside: auto; }
        .terms-table tr { page-break-inside: avoid; }
        .terms-table td { width: 50%; padding: 1pt 7pt 1pt 0; vertical-align: top; }
        .terms-table td:last-child { padding: 1pt 0 1pt 7pt; }
        .terms-independent-row > td { vertical-align: top; }
        .term-paragraph { margin: 0 0 2pt; line-height: 1.17; font-size: 6.4pt; }
        .term-paragraph:last-child { margin-bottom: 0; }
        .term-ar { text-align: right; font-size: 6.4pt; }
        .notes { border-top: .45pt solid #cdbb98; margin-top: 6pt; padding-top: 4pt; font-size: 7pt; }
        .notes-heading { color: #ad915d; font-size: 7pt; font-weight: bold; margin-bottom: 2pt; }
        .footer { margin-top: 7pt; color: #5b5650; font-size: 6.5pt; page-break-inside: auto; }
        .branding-strip { border-top: .6pt solid #ad915d; margin-top: 5pt; page-break-inside: avoid; }
        .branding-logos { table-layout: fixed; }
        .branding-logos td { text-align: center; padding: 3pt 2pt 2pt; vertical-align: top; }
        .brand-art { height: 22pt; line-height: 22pt; }
        .brand-art img { max-width: 55pt; max-height: 21pt; vertical-align: middle; }
        .brand-caption { font-size: 6pt; line-height: 1.18; overflow-wrap: break-word; margin-top: 2pt; }
        .branding-statement { border-top: .6pt solid #cbb992; margin-top: 4pt; font-size: 6pt; }
        .branding-statement td { width: 50%; padding: 4pt 4pt 0 0; overflow-wrap: break-word; }
        .branding-statement td:last-child { padding-left: 4pt; padding-right: 0; }
        .company-footer { border-top: .6pt solid #cbb992; margin-top: 5pt; padding-top: 4pt; font-size: 6pt; }
        .company-footer .right { text-align: right; }
        .non-tax-footer { margin-top: 4pt; color: #795b28; font-size: 6.6pt; font-weight: bold; text-align: center; }
        .customer-copy { margin-top: 5pt; text-align: right; font-size: 6.8pt; font-weight: bold; }
        @media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style>
</head>
<body>
@php
    $seller = $quotation->seller_snapshot ?? [];
    $isPdf = ($documentMode ?? 'pdf') === 'pdf';
    $ar = function (?string $text, int $width = 62) use ($isPdf, $arabic): string {
        if (! $isPdf) {
            return (string) $text;
        }
        $lines = [];
        foreach (preg_split('/\R/u', (string) $text) ?: [] as $paragraph) {
            $line = '';
            foreach (preg_split('/\s+/u', $paragraph) ?: [] as $word) {
                if ($line !== '' && mb_strlen($line.' '.$word) > $width) {
                    $lines[] = $line;
                    $line = '';
                }
                $line .= ($line === '' ? '' : ' ').$word;
            }
            $lines[] = $line;
        }
        return $arabic->forDompdf(implode("\n", $lines));
    };
    $arClass = $isPdf ? 'arabic-pdf' : 'arabic';
    $isProforma = $quotation->document_type === \App\Enums\QuotationDocumentType::ProformaInvoice;
    $documentTitle = $isProforma ? 'PROFORMA INVOICE' : 'QUOTATION';
    $documentTitleAr = $isProforma ? 'فاتورة مبدئية' : 'عرض سعر';
    $referenceLabel = $isProforma ? 'Proforma #' : 'Quotation #';
    $referenceTitleAr = $isProforma ? 'رقم الفاتورة المبدئية' : 'رقم عرض السعر';
    $termParagraphs = static function (?string $source): array {
        $text = trim(str_replace(["\r\n", "\r"], "\n", (string) $source));
        if ($text === '') {
            return [];
        }
        $text = preg_replace('/\n(?=\s*\p{N}{1,2}[.)]\s)/u', "\n\n", $text) ?? $text;
        return array_values(array_filter(array_map(
            static fn (string $block): string => trim(preg_replace('/[ \t]*\n[ \t]*/u', ' ', $block) ?? $block),
            preg_split('/\n(?:[ \t]*\n)+/u', $text) ?: []
        ), static fn (string $paragraph): bool => $paragraph !== ''));
    };
    $termsChunks = static function (array $paragraphs, int $limit = 480): array {
        $groups = [];
        $current = [];
        $length = 0;
        foreach ($paragraphs as $paragraph) {
            $remaining = trim($paragraph);
            while (mb_strlen($remaining) > $limit) {
                $part = mb_substr($remaining, 0, $limit);
                $space = mb_strrpos($part, ' ');
                if ($space === false || $space < (int) ($limit / 3)) {
                    $space = $limit;
                }
                if ($current !== []) {
                    $groups[] = $current; $current = []; $length = 0;
                }
                $piece = trim(mb_substr($remaining, 0, $space));
                if ($piece !== '') {
                    $groups[] = [$piece];
                }
                $remaining = trim(mb_substr($remaining, $space));
            }
            if ($remaining === '') { continue; }
            if ($current !== [] && $length + mb_strlen($remaining) > $limit) {
                $groups[] = $current; $current = []; $length = 0;
            }
            $current[] = $remaining; $length += mb_strlen($remaining);
        }
        if ($current !== []) { $groups[] = $current; }
        return $groups;
    };
    $termsEn = $termParagraphs($quotation->terms_en_snapshot);
    $termsAr = $termParagraphs($quotation->terms_ar_snapshot);
    $termsEnglishWidth = 50;
    $usesExtendedTerms = collect([...$termsEn, ...$termsAr])
        ->sum(fn ($line): int => max(1, (int) ceil(mb_strlen($line) / 50))) > 24;
    $renewedTermsEn = [];
    $renewedTermsAr = [];
    $termsChunks = $termsChunks;
    $footerBranding = $footerBranding ?? ['entries' => [], 'statement_en' => '', 'statement_ar' => ''];
@endphp
<div class="document-page">
    <table class="document-header"><tr>
        <td style="width: 22%">@if($logoDataUri)<img class="logo" src="{{ $logoDataUri }}" alt="Company logo">@endif</td>
        <td style="width: 46%">
            <div class="company-name">{{ $seller['company_name_en'] ?? '' }}</div>
            @if(filled($seller['company_name_ar'] ?? null))<div class="company-name-ar {{ $arClass }}">{{ $ar($seller['company_name_ar'], 54) }}</div>@endif
        </td>
        <td style="width: 32%">
            <div class="title">{{ $documentTitle }}</div>
            <div class="title-ar {{ $arClass }}">{{ $ar($documentTitleAr) }}</div>
            <div class="non-tax">NOT A TAX INVOICE</div>
        </td>
    </tr></table>
    <table class="seller-details"><tr>
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
    </tr></table>
    <div class="rule"></div>
    <table class="details-shell"><tr>
        <td>
            <div class="section-label">BILL TO <span class="{{ $arClass }}">{{ $ar('المستلم') }}</span></div>
            <table class="fields">
                @if(filled($quotation->customer_company))<tr><td class="label">Company</td><td class="value">{{ $quotation->customer_company }}</td></tr>@endif
                <tr><td class="label">Customer</td><td class="value">{{ $quotation->customer_name }}</td></tr>
                @if(filled($quotation->customer_address))<tr><td class="label">Billing address</td><td class="value">{{ $quotation->customer_address }}</td></tr>@endif
                @if(filled($quotation->customer_phone))<tr><td class="label">Phone</td><td class="value">{{ $quotation->customer_phone }}</td></tr>@endif
                @if(filled($quotation->customer_email))<tr><td class="label">Email</td><td class="value">{{ $quotation->customer_email }}</td></tr>@endif
                @if(filled($quotation->customer_trn))<tr><td class="label">Customer TRN</td><td class="value invoice-trn" dir="ltr">{{ $quotation->customer_trn }}</td></tr>@endif
            </table>
        </td>
        <td>
            <div class="section-label">{{ $isProforma ? 'PROFORMA DETAILS' : 'QUOTATION DETAILS' }} <span class="{{ $arClass }}">{{ $ar($isProforma ? 'تفاصيل الفاتورة المبدئية' : 'تفاصيل عرض السعر') }}</span></div>
            <table class="fields">
                <tr><td class="label">{{ $referenceLabel }}</td><td class="value">{{ $quotation->reference }}</td></tr>
                @if(filled($quotation->external_reference))<tr><td class="label">Reference</td><td class="value">{{ $quotation->external_reference }}</td></tr>@endif
                <tr><td class="label">Date</td><td class="value">{{ $quotation->quotation_date->format('d M Y') }}</td></tr>
                <tr><td class="label">Valid Until</td><td class="value">{{ $quotation->valid_until->format('d M Y') }}</td></tr>
            </table>
        </td>
    </tr></table>
    <div class="items-heading">ITEMS <span class="{{ $arClass }}">{{ $ar('الأصناف') }}</span></div>
    <table class="items">
        <thead><tr>
            <th style="width: 40%">Description <span class="th-ar {{ $arClass }}">{{ $ar('الوصف') }}</span></th>
            <th style="width: 8%; text-align: right">Qty <span class="th-ar {{ $arClass }}">{{ $ar('الكمية') }}</span></th>
            <th style="width: 15%; text-align: right">Unit incl. VAT <span class="th-ar {{ $arClass }}">{{ $ar('سعر الوحدة') }}</span></th>
            <th style="width: 10%; text-align: right">Discount <span class="th-ar {{ $arClass }}">{{ $ar('الخصم') }}</span></th>
            <th style="width: 10%; text-align: right">VAT (AED) <span class="th-ar {{ $arClass }}">{{ $ar('الضريبة') }}</span></th>
            <th style="width: 17%; text-align: right">Total incl. VAT <span class="th-ar {{ $arClass }}">{{ $ar('شامل الضريبة') }}</span></th>
        </tr></thead>
        <tbody>
            @foreach($quotation->items as $item)
                <tr>
                    <td>{{ $item->description }} @if(filled($item->sku))<div class="sku">{{ $item->sku }}</div>@endif</td>
                    <td class="num">{{ $item->quantity }}</td>
                    <td class="num">{{ number_format((float) $item->unit_price_including_vat, 2) }}</td>
                    <td class="num">{{ number_format((float) $item->discount_amount, 2) }}</td>
                    <td class="num">{{ number_format((float) $item->vat_amount, 2) }}</td>
                    <td class="num">{{ number_format((float) $item->total_including_vat, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <table class="closing"><tr>
        <td>@if($stampDataUri)<div class="stamp-area"><img class="stamp" src="{{ $stampDataUri }}" alt="Company stamp"></div>@endif</td>
        <td><table class="summary">
            <tr><td>Subtotal (excl. VAT)</td><td class="{{ $arClass }}">{{ $ar('المجموع الفرعي') }}</td><td>{{ number_format((float) $quotation->subtotal_excluding_vat, 2) }}</td></tr>
            @if((float) $quotation->discount_total > 0)<tr><td>Discount</td><td class="{{ $arClass }}">{{ $ar('الخصم') }}</td><td>{{ number_format((float) $quotation->discount_total, 2) }}</td></tr>@endif
            <tr><td>Estimated VAT</td><td class="{{ $arClass }}">{{ $ar('ضريبة القيمة المضافة') }}</td><td>{{ number_format((float) $quotation->vat_amount, 2) }}</td></tr>
            <tr class="grand"><td>{{ $isProforma ? 'Proforma total (AED)' : 'Quotation total (AED)' }}</td><td class="{{ $arClass }}">{{ $ar('الإجمالي') }}</td><td>{{ number_format((float) $quotation->grand_total, 2) }}</td></tr>
        </table></td>
    </tr></table>
    @if($termsEn !== [] || $termsAr !== [])
        @include('invoices.partials.tax-invoice-terms')
    @endif
    @if(filled($quotation->notes))<div class="notes"><div class="notes-heading">Notes <span class="{{ $arClass }}">{{ $ar('ملاحظات') }}</span></div>{!! nl2br(e($quotation->notes)) !!}</div>@endif
    <div class="footer">
        @include('invoices.partials.tax-invoice-branding')
        <table class="company-footer"><tr>
            <td>Trade License No: {{ $seller['trade_license_number'] ?? '—' }} &nbsp; | &nbsp; DED Reg No: {{ $seller['ded_registration_number'] ?? '—' }}</td>
            <td class="right">TRN: {{ $seller['trn'] ?? '—' }}</td>
        </tr></table>
        @if(filled($seller['legal_statement_en'] ?? null))
            <div class="notes">{{ $seller['legal_statement_en'] }}</div>
        @endif
        @if(filled($seller['legal_statement_ar'] ?? null))
            <div class="notes {{ $arClass }}">{{ $ar($seller['legal_statement_ar']) }}</div>
        @endif
        <div class="non-tax-footer">NOT A TAX INVOICE &nbsp; • &nbsp; {{ $isProforma ? 'Proforma only — subject to acceptance and actual Tax Invoice issuance' : 'Quotation only — valid until the date above' }}</div>
        <div class="customer-copy">Customer copy <span class="{{ $arClass }}">{{ $ar('نسخة العميل') }}</span></div>
    </div>
</div>
@if(($documentMode ?? null) === 'print')
<script>window.addEventListener('load', () => window.print());</script>
@endif
</body>
</html>
