<div class="terms">
    <table class="terms-table">
        <thead><tr><td class="terms-title" style="width: {{ $termsEnglishWidth }}%">Terms &amp; Conditions</td><td class="terms-title {{ $arClass }}" style="width: {{ 100 - $termsEnglishWidth }}%">{{ $ar('الشروط والأحكام') }}</td></tr></thead>
        <tbody>
        @foreach([[$termsEn, $termsAr], [$renewedTermsEn, $renewedTermsAr]] as [$blockEn, $blockAr])
            @continue(count($blockEn) === 0 && count($blockAr) === 0)
            @php
                // Large legal sections are split into small breakable groups. Each
                // language flows inside its column; lines are never paired by index.
                $enGroups = $usesExtendedTerms ? $termsChunks($blockEn) : [$blockEn];
                $arGroups = $usesExtendedTerms ? $termsChunks($blockAr) : [$blockAr];
            @endphp
            @for($index = 0; $index < max(count($enGroups), count($arGroups)); $index++)
                <tr class="terms-independent-row">
                    <td style="width: {{ $termsEnglishWidth }}%">
                        @foreach($enGroups[$index] ?? [] as $paragraph)
                            <div class="term-en term-paragraph">{{ $paragraph }}</div>
                        @endforeach
                    </td>
                    <td style="width: {{ 100 - $termsEnglishWidth }}%">
                        @foreach($arGroups[$index] ?? [] as $paragraph)
                            <div class="term-ar term-paragraph {{ $arClass }}">{{ $ar($paragraph, (int) (62 * (100 - $termsEnglishWidth) / 50)) }}</div>
                        @endforeach
                    </td>
                </tr>
            @endfor
        @endforeach
        </tbody>
    </table>
</div>
