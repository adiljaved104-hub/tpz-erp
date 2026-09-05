@props(['branding'])

<header data-auth-branding class="fi-simple-header">
    @if($branding['logo_url'])
        <img src="{{ $branding['logo_url'] }}" alt="{{ $branding['title'] }}" style="display:block;max-width:13rem;max-height:5rem;object-fit:contain" />
    @endif
    <h1 class="fi-simple-header-heading">{{ $branding['title'] }}</h1>
    <p class="fi-simple-header-subheading">{{ $branding['subtitle'] }}</p>
</header>
