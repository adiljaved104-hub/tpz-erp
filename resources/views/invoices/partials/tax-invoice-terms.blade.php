<div class="terms">
    <div class="terms-title">Terms &amp; Conditions (<span class="{{ $arClass }}">{{ $ar('الشروط والأحكام') }}</span>)</div>
    @foreach([[$termsEn, $termsAr], [$renewedTermsEn, $renewedTermsAr]] as [$blockEn, $blockAr])
        @for($index = 0; $index < max(count($blockEn), count($blockAr)); $index++)
            @continue(blank($blockEn[$index] ?? null) && blank($blockAr[$index] ?? null))
            <div class="term">
                @if(filled($blockEn[$index] ?? null))
                    <div class="term-en">• {{ $blockEn[$index] }}</div>
                @endif
                @if(filled($blockAr[$index] ?? null))
                    <div class="term-ar {{ $arClass }}">{{ $ar($blockAr[$index]) }}</div>
                @endif
            </div>
        @endfor
    @endforeach
</div>
