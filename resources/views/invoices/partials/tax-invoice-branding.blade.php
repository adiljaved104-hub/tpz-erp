@if(count($footerBranding['entries'] ?? []) > 0)
    <div class="branding-strip">
        <table class="branding-logos"><tr>
            @foreach($footerBranding['entries'] as $entry)
                <td style="width: {{ 100 / count($footerBranding['entries']) }}%">
                    <div class="brand-art"><img src="{{ $entry['image'] }}" alt="{{ $entry['label'] }}"></div>
                    <div class="brand-caption">{{ $entry['caption'] }}</div>
                </td>
            @endforeach
        </tr></table>
        @if(filled($footerBranding['statement_en']) || filled($footerBranding['statement_ar']))
            <table class="branding-statement"><tr><td>{{ $footerBranding['statement_en'] }}</td><td class="{{ $arClass }}">{{ $isPdf ? app(\App\Services\Invoices\InvoiceFooterBrandingService::class)->preserveLatinRuns($ar($footerBranding['statement_ar'])) : $footerBranding['statement_ar'] }}</td></tr></table>
        @endif
    </div>
@endif
