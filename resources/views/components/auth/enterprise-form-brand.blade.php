@props(['branding'])
<div class="tpz-auth-identity" aria-label="{{ $branding['title'] }}">
    @if(!empty($branding['logo_url']))
        <img class="tpz-auth-identity-logo" src="{{ $branding['logo_url'] }}" alt="{{ $branding['title'] }}">
    @else
        <div class="tpz-auth-identity-monogram" aria-hidden="true">TPZ<span></span></div>
    @endif
    <p class="tpz-auth-identity-name">{{ $branding['title'] }}</p>
</div>