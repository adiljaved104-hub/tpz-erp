<div class="terms">
    <table class="terms-table">
        <thead><tr><td class="terms-title" style="width: {{ $termsEnglishWidth }}%">Terms &amp; Conditions</td><td class="terms-title {{ $arClass }}" style="width: {{ 100 - $termsEnglishWidth }}%">{{ $ar('الشروط والأحكام') }}</td></tr></thead>
        <tbody>
        @foreach([[$termsEn, $termsAr], [$renewedTermsEn, $renewedTermsAr]] as [$blockEn, $blockAr])
            @for($index = 0; $index < max(count($blockEn), count($blockAr)); $index++)
                @continue(blank($blockEn[$index] ?? null) && blank($blockAr[$index] ?? null))
                <tr>
                    <td class="term-en" style="width: {{ $termsEnglishWidth }}%">{{ $blockEn[$index] ?? '' }}</td>
                    <td class="term-ar {{ $arClass }}" style="width: {{ 100 - $termsEnglishWidth }}%">{{ $ar($blockAr[$index] ?? '', (int) (62 * (100 - $termsEnglishWidth) / 50)) }}</td>
                </tr>
            @endfor
        @endforeach
        </tbody>
    </table>
</div>
