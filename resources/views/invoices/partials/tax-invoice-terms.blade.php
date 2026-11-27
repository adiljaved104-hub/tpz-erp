<div class="terms">
    <table class="terms-table">
        <thead><tr><td class="terms-title" style="width: {{ $termsEnglishWidth }}%">Terms &amp; Conditions</td><td class="terms-title {{ $arClass }}" style="width: {{ 100 - $termsEnglishWidth }}%">{{ $ar('الشروط والأحكام') }}</td></tr></thead>
        <tbody>
        @foreach([[$termsEn, $termsAr], [$renewedTermsEn, $renewedTermsAr]] as [$blockEn, $blockAr])
            @continue(count($blockEn) === 0 && count($blockAr) === 0)
            @if($usesExtendedTerms)
                {{-- Long content may need multiple A4 pages: keep small, breakable rows. --}}
                @for($index = 0; $index < max(count($blockEn), count($blockAr)); $index++)
                    <tr>
                        <td class="term-en" style="width: {{ $termsEnglishWidth }}%">{{ $blockEn[$index] ?? '' }}</td>
                        <td class="term-ar {{ $arClass }}" style="width: {{ 100 - $termsEnglishWidth }}%">{{ $ar($blockAr[$index] ?? '', (int) (62 * (100 - $termsEnglishWidth) / 50)) }}</td>
                    </tr>
                @endfor
            @else
                {{-- Translations have different line counts and wrap differently. They
                     must flow independently instead of sharing a table row per line:
                     row heights otherwise insert large blank gaps in the other language. --}}
                <tr class="terms-independent-row">
                    <td style="width: {{ $termsEnglishWidth }}%">
                        @foreach($blockEn as $line)
                            <div class="term-en term-paragraph">{{ $line }}</div>
                        @endforeach
                    </td>
                    <td style="width: {{ 100 - $termsEnglishWidth }}%">
                        @foreach($blockAr as $line)
                            <div class="term-ar term-paragraph {{ $arClass }}">{{ $ar($line, (int) (62 * (100 - $termsEnglishWidth) / 50)) }}</div>
                        @endforeach
                    </td>
                </tr>
            @endif
        @endforeach
        </tbody>
    </table>
</div>
