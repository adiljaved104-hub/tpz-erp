<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $quotation->reference }}</title>

    <style>
        @page {
            size: A4 portrait;
            margin: 9mm 11mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #fff;
            color: #172033;
            font-family: "DejaVu Sans", sans-serif;
            font-size: 9px;
            line-height: 1.35;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        td,
        th {
            vertical-align: top;
        }

        .rule {
            border-top: 2px solid #d7e0ea;
            margin: 7px 0 9px;
        }

        .document-header td {
            vertical-align: middle;
        }

        .logo {
            max-height: 55px;
            max-width: 155px;
        }

        .title {
            color: #0b4478;
            font-size: 21px;
            font-weight: 700;
            letter-spacing: .5px;
            margin: 0;
            text-align: right;
        }

        .title-ar {
            color: #64748b;
            font-size: 13px;
            font-weight: 700;
            margin-top: 4px;
            text-align: right;
        }

        .details-shell {
            background: #f5f7fa;
            border-radius: 7px;
            margin-bottom: 10px;
        }

        .details-shell > tbody > tr > td {
            padding: 10px 12px;
            width: 50%;
        }

        .details-shell > tbody > tr > td + td {
            border-left: 1px solid #dbe3ec;
        }

        .section-label {
            border-bottom: 2px solid #dbe3ec;
            color: #0b4478;
            font-size: 8px;
            font-weight: 700;
            margin-bottom: 7px;
            padding-bottom: 4px;
            text-transform: uppercase;
        }

        .company-name {
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .arabic,
        .arabic-pdf {
            font-family: "DejaVu Sans", sans-serif;
            text-align: right;
        }

        .arabic {
            direction: rtl;
            unicode-bidi: embed;
        }

        .arabic-pdf {
            direction: ltr;
            unicode-bidi: bidi-override;
        }

        .arabic-line {
            color: #64748b;
            margin: 3px 0 5px;
        }

        .trn {
            color: #0b4478;
            font-weight: 700;
            margin-top: 7px;
        }

        .bill-to {
            text-align: right;
        }

        .quotation-details {
            margin-top: 15px;
            text-align: right;
        }

        .quotation-details div {
            margin: 2px 0;
        }

        .items {
            margin-bottom: 10px;
            table-layout: fixed;
        }

        .items thead {
            display: table-header-group;
        }

        .items tr {
            page-break-inside: avoid;
        }

        .items th {
            background: #0b4478;
            color: #fff;
            font-size: 8px;
            padding: 7px 6px;
            text-align: left;
        }

        .items th.num,
        .items td.num {
            text-align: right;
        }

        .items td {
            border-bottom: 1px solid #dbe3ec;
            padding: 7px 6px;
        }

        .items .description {
            font-weight: 600;
        }

        .sku {
            color: #64748b;
            font-size: 8px;
            margin-top: 2px;
        }

        .summary-layout > tbody > tr > td {
            width: 56%;
        }

        .summary-layout > tbody > tr > td + td {
            padding-left: 12px;
            width: 44%;
        }

        .summary-layout {
            page-break-inside: avoid;
        }

        .closing-section.long-layout {
            page-break-inside: avoid;
        }

        .document-page.bottom-footer .footer {
            bottom: 0;
            left: 0;
            margin-top: 0;
            position: fixed;
            right: 0;
        }

        .terms {
            border-left: 3px solid #0b4478;
            background: #f7f9fb;
            padding: 9px 10px;
        }

        .terms-title {
            font-size: 9px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .term {
            margin-bottom: 5px;
        }

        .term-en {
            font-weight: 600;
        }

        .term-ar {
            color: #64748b;
            font-size: 8px;
            margin-top: 1px;
        }

        .notes {
            background: #f7f9fb;
            border-left: 3px solid #94a3b8;
            margin-top: 7px;
            padding: 7px 10px;
        }

        .notes-title {
            font-weight: 700;
            margin-bottom: 3px;
        }

        .totals {
            background: #f7f9fb;
        }

        .totals td {
            padding: 7px 9px;
        }

        .totals td:last-child {
            font-weight: 700;
            text-align: right;
            white-space: nowrap;
        }

        .grand td {
            border-top: 2px solid #c9d5e2;
            color: #0b4478;
            font-size: 13px;
            font-weight: 700;
        }

        .document-page {
            position: relative;
        }

        .footer-spacer {
            display: block;
        }

        .footer {
            color: #64748b;
            font-size: 7px;
            margin-top: 10px;
            text-align: center;
            page-break-inside: avoid;
        }

        .stamp-area {
            margin-top: 9px;
            min-height: 72px;
            text-align: right;
        }

        .stamp {
            height: auto;
            max-height: 82px;
            max-width: 110px;
            width: auto;
        }

        .company-footer {
            border-top: 1px solid #dbe3ec;
            padding-top: 6px;
        }

        .company-footer div {
            margin: 2px 0;
        }

        .footer strong {
            color: #334155;
        }

        .legal {
            font-size: 6.5px;
            margin-top: 5px !important;
        }

        @media print {
            body {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .no-print {
                display: none !important;
            }
        }
    </style>
</head>

<body>

@php
    $seller = $quotation->seller_snapshot ?? [];

    $isPdf = ($documentMode ?? 'pdf') === 'pdf';

    /*
     * Use the same Arabic helper as Tax Invoice when it is supplied
     * by the quotation PDF service.
     *
     * The fallback prevents the quotation from crashing if the
     * helper has not yet been passed to this template.
     */
    $arabicService = $arabic ?? null;

    $ar = function (?string $text) use ($isPdf, $arabicService): string {
        if ($isPdf && $arabicService) {
            return $arabicService->forDompdf($text);
        }

        return (string) $text;
    };

    $arClass = ($isPdf && $arabicService)
        ? 'arabic-pdf'
        : 'arabic';

    $documentLabel = $quotation->document_type->label();

    $isProforma = str_contains(
        strtolower((string) $documentLabel),
        'proforma'
    );

    $documentTitle = $isProforma
        ? 'PROFORMA INVOICE'
        : 'QUOTATION';

    $documentTitleAr = $isProforma
        ? 'فاتورة مبدئية'
        : 'عرض سعر';

    $detailsTitle = $isProforma
        ? 'PROFORMA DETAILS'
        : 'QUOTATION DETAILS';

    $detailsTitleAr = $isProforma
        ? 'تفاصيل الفاتورة المبدئية'
        : 'تفاصيل عرض السعر';

    $referenceLabel = $isProforma
        ? 'Proforma #'
        : 'Quotation #';

    $termsEn = preg_split(
        '/\R/u',
        trim((string) $quotation->terms_en_snapshot)
    ) ?: [];

    $termsAr = preg_split(
        '/\R/u',
        trim((string) $quotation->terms_ar_snapshot)
    ) ?: [];

    $termCount = max(
        count($termsEn),
        count($termsAr)
    );

    $vatRate = (string) ($quotation->vat_rate ?? '5');

    $vatDivisor = bcadd(
        '1',
        bcdiv($vatRate, '100', 8),
        8
    );

    $itemLayoutUnits = $quotation->items->sum(
        fn ($item): int => max(
            1,
            (int) ceil(
                mb_strlen((string) $item->description) / 72
            )
        )
    );

    $usesBottomFooter =
        $quotation->items->count() <= 3
        && $itemLayoutUnits <= 3;

    /*
     * No QR block exists on Quotations, so the footer spacer
     * can remain simpler than Tax Invoice.
     */
    $footerSpacerMm = $usesBottomFooter
        ? 0
        : max(
            0,
            64 - (max(0, $itemLayoutUnits - 1) * 8)
        );

    $usesLongLayout =
        ! $usesBottomFooter
        && $footerSpacerMm === 0;
@endphp


<div class="document-page{{ $usesBottomFooter ? ' bottom-footer' : '' }}">

    {{-- HEADER --}}
    <table class="document-header">
        <tr>
            <td style="width: 50%">
                @if($logoDataUri)
                    <img
                        class="logo"
                        src="{{ $logoDataUri }}"
                        alt="Company logo"
                    >
                @endif
            </td>

            <td style="width: 50%">
                <div class="title">
                    {{ $documentTitle }}
                </div>

                <div class="title-ar {{ $arClass }}">
                    {{ $ar($documentTitleAr) }}
                </div>
            </td>
        </tr>
    </table>

    <div class="rule"></div>


    {{-- SELLER / CUSTOMER DETAILS --}}
    <table class="details-shell">
        <tr>

            {{-- FROM --}}
            <td>
                <div class="section-label">
                    FROM /
                    <span class="{{ $arClass }}">
                        {{ $ar('من') }}
                    </span>
                </div>

                <div class="company-name">
                    {{ $seller['company_name_en'] ?? '' }}
                </div>

                @if(filled($seller['company_name_ar'] ?? null))
                    <div class="company-name {{ $arClass }}">
                        {{ $ar($seller['company_name_ar']) }}
                    </div>
                @endif

                @if(filled($seller['address_en'] ?? null))
                    <div>
                        {{ $seller['address_en'] }}
                    </div>
                @endif

                @if(filled($seller['address_ar'] ?? null))
                    <div class="arabic-line {{ $arClass }}">
                        {{ $ar($seller['address_ar']) }}
                    </div>
                @endif

                <div class="trn">
                    TRN /
                    <span class="{{ $arClass }}">
                        {{ $ar('الرقم الضريبي') }}
                    </span>:
                    {{ $seller['trn'] ?? '—' }}
                </div>
            </td>


            {{-- BILL TO --}}
            <td class="bill-to">

                <div class="section-label">
                    BILL TO /
                    <span class="{{ $arClass }}">
                        {{ $ar('إلى') }}
                    </span>
                </div>

                @if(filled($quotation->customer_company))
                    <div class="company-name">
                        {{ $quotation->customer_company }}
                    </div>

                    <div>
                        {{ $quotation->customer_name }}
                    </div>
                @else
                    <div class="company-name">
                        {{ $quotation->customer_name }}
                    </div>
                @endif

                @if(filled($quotation->customer_address))
                    <div>
                        {{ $quotation->customer_address }}
                    </div>
                @endif

                @if(filled($quotation->customer_phone))
                    <div>
                        {{ $quotation->customer_phone }}
                    </div>
                @endif

                @if(filled($quotation->customer_email))
                    <div>
                        {{ $quotation->customer_email }}
                    </div>
                @endif

                @if(filled($quotation->customer_trn))
                    <div class="trn">
                        Customer TRN:
                        {{ $quotation->customer_trn }}
                    </div>
                @endif


                {{-- QUOTATION DETAILS --}}
                <div class="quotation-details">

                    <div class="section-label">
                        {{ $detailsTitle }} /
                        <span class="{{ $arClass }}">
                            {{ $ar($detailsTitleAr) }}
                        </span>
                    </div>

                    <div>
                        {{ $referenceLabel }}:
                        <strong>
                            {{ $quotation->reference }}
                        </strong>
                    </div>

                    @if(filled($quotation->external_reference))
                        <div>
                            Reference:
                            <strong>
                                {{ $quotation->external_reference }}
                            </strong>
                        </div>
                    @endif

                    <div>
                        Date:
                        <strong>
                            {{ $quotation->quotation_date->format('d M Y') }}
                        </strong>
                    </div>

                    <div>
                        Valid Until:
                        <strong>
                            {{ $quotation->valid_until->format('d M Y') }}
                        </strong>
                    </div>

                </div>

            </td>
        </tr>
    </table>


    {{-- ITEMS --}}
    <table class="items">

        <thead>
        <tr>
            <th style="width: 5%">
                #
            </th>

            <th style="width: 44%">
                DESCRIPTION /
                <span class="{{ $arClass }}">
                    {{ $ar('الوصف') }}
                </span>
            </th>

            <th
                class="num"
                style="width: 8%"
            >
                QTY
            </th>

            <th
                class="num"
                style="width: 15%"
            >
                UNIT PRICE
            </th>

            <th
                class="num"
                style="width: 12%"
            >
                DISCOUNT
            </th>

            <th
                class="num"
                style="width: 16%"
            >
                TOTAL (INCL VAT)
            </th>
        </tr>
        </thead>


        <tbody>

        @foreach($quotation->items as $item)

            @php
                $unitExcludingVat = bcadd(
                    bcdiv(
                        (string) $item->unit_price_including_vat,
                        $vatDivisor,
                        4
                    ),
                    '0.005',
                    2
                );
            @endphp

            <tr>

                <td>
                    {{ $item->line_number }}
                </td>

                <td class="description">
                    {{ $item->description }}

                    @if(filled($item->sku))
                        <div class="sku">
                            {{ $item->sku }}
                        </div>
                    @endif
                </td>

                <td class="num">
                    {{ $item->quantity }}
                </td>

                <td class="num">
                    {{ number_format(
                        (float) $unitExcludingVat,
                        2
                    ) }}
                </td>

                <td class="num">
                    {{ number_format(
                        (float) $item->discount_amount,
                        2
                    ) }}
                </td>

                <td class="num">
                    {{ number_format(
                        (float) $item->total_including_vat,
                        2
                    ) }}
                </td>

            </tr>

        @endforeach

        </tbody>
    </table>


    {{-- TERMS / TOTALS --}}
    <div class="closing-section{{ $usesLongLayout ? ' long-layout' : '' }}">

        <table class="summary-layout">
            <tr>

                {{-- TERMS --}}
                <td>

                    <div class="terms">

                        <div class="terms-title">
                            Terms &amp; Conditions
                            (
                            <span class="{{ $arClass }}">
                                {{ $ar('الشروط والأحكام') }}
                            </span>
                            )
                        </div>

                        @for(
                            $index = 0;
                            $index < $termCount;
                            $index++
                        )

                            <div class="term">

                                @if(filled($termsEn[$index] ?? null))
                                    <div class="term-en">
                                        • {{ $termsEn[$index] }}
                                    </div>
                                @endif

                                @if(filled($termsAr[$index] ?? null))
                                    <div class="term-ar {{ $arClass }}">
                                        {{ $ar($termsAr[$index]) }}
                                    </div>
                                @endif

                            </div>

                        @endfor

                    </div>


                    @if(filled($quotation->notes))

                        <div class="notes">

                            <div class="notes-title">
                                Notes
                            </div>

                            {!! nl2br(
                                e($quotation->notes)
                            ) !!}

                        </div>

                    @endif

                </td>


                {{-- TOTALS --}}
                <td>

                    <table class="totals">

                        <tr>
                            <td>
                                Subtotal /
                                <span class="{{ $arClass }}">
                                    {{ $ar('المجموع الفرعي') }}
                                </span>
                            </td>

                            <td>
                                {{ number_format(
                                    (float) $quotation->subtotal_excluding_vat,
                                    2
                                ) }}
                            </td>
                        </tr>


                        @if((float) $quotation->discount_total > 0)

                            <tr>
                                <td>
                                    Discount
                                </td>

                                <td>
                                    {{ number_format(
                                        (float) $quotation->discount_total,
                                        2
                                    ) }}
                                </td>
                            </tr>

                        @endif


                        <tr>
                            <td>
                                VAT
                                ({{ number_format(
                                    (float) ($quotation->vat_rate ?? 5),
                                    0
                                ) }}%)
                                /
                                <span class="{{ $arClass }}">
                                    {{ $ar('ضريبة القيمة المضافة') }}
                                </span>
                            </td>

                            <td>
                                {{ number_format(
                                    (float) $quotation->vat_amount,
                                    2
                                ) }}
                            </td>
                        </tr>


                        <tr class="grand">

                            <td>
                                GRAND TOTAL (AED)
                            </td>

                            <td>
                                {{ number_format(
                                    (float) $quotation->grand_total,
                                    2
                                ) }}
                            </td>

                        </tr>

                    </table>


                    {{-- SAME STAMP POSITION AS TAX INVOICE --}}
                    @if($stampDataUri ?? null)

                        <div class="stamp-area">

                            <img
                                class="stamp"
                                src="{{ $stampDataUri }}"
                                alt="Company stamp"
                            >

                        </div>

                    @endif

                </td>

            </tr>
        </table>


        {{-- FOOTER SPACING --}}
        @if($footerSpacerMm > 0)

            <div
                class="footer-spacer"
                style="height: {{ $footerSpacerMm }}mm"
            ></div>

        @endif


        {{-- COMPANY FOOTER --}}
        <div class="footer">

            <div class="company-footer">

                <div>
                    <strong>Trade License No:</strong>
                    {{ $seller['trade_license_number'] ?? '—' }}

                    &nbsp; | &nbsp;

                    <strong>DED Reg No:</strong>
                    {{ $seller['ded_registration_number'] ?? '—' }}

                    &nbsp; | &nbsp;

                    <strong>TRN:</strong>
                    {{ $seller['trn'] ?? '—' }}
                </div>


                <div>
                    <strong>Registered Address:</strong>
                    {{ $seller['address_en'] ?? '—' }}
                </div>


                <div>

                    <strong>Phone:</strong>
                    {{ $seller['phone'] ?? '—' }}

                    &nbsp; | &nbsp;

                    <strong>Mobile:</strong>
                    {{ $seller['mobile'] ?? '—' }}

                    &nbsp; | &nbsp;

                    <strong>Email:</strong>
                    {{ $seller['email'] ?? '—' }}

                </div>


                @if(filled($seller['legal_statement_en'] ?? null))

                    <div class="legal">
                        {{ $seller['legal_statement_en'] }}
                    </div>

                @endif


                @if(filled($seller['legal_statement_ar'] ?? null))

                    <div class="legal {{ $arClass }}">
                        {{ $ar(
                            $seller['legal_statement_ar']
                        ) }}
                    </div>

                @endif

            </div>

        </div>

    </div>

</div>


@if(($documentMode ?? null) === 'print')

    <script>
        window.addEventListener(
            'load',
            () => window.print()
        )
    </script>

@endif

</body>
</html>