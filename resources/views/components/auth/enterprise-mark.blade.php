@props(['branding'])
<div class="tpz-enterprise-mark" aria-label="Tech Point Zone ERP">
    @if(filled($branding['logo_url'] ?? null))
        <img src="{{ $branding['logo_url'] }}" alt="Tech Point Zone" class="tpz-enterprise-mark-logo" />
    @else
        <strong class="tpz-enterprise-mark-monogram">TPZ</strong>
    @endif
    <div class="tpz-enterprise-mark-name">TECH POINT ZONE <span>ERP</span></div>
</div>