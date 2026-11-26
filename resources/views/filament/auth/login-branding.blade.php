@php
    $branding = app(\App\Services\LoginBrandingService::class)->presentation();
    $isChallenge = filled($this->userUndertakingMultiFactorAuthentication);
@endphp
@include('auth.enterprise-styles')
<x-auth.enterprise-hero :verification="$isChallenge" />
<div class="tpz-auth-form-brand">
    <x-auth.branding :branding="$branding" />
</div>
<div class="tpz-auth-form-heading">
    <h2>{{ $isChallenge ? 'Enter Verification Code' : 'Welcome Back' }}</h2>
    <p>{{ $isChallenge ? 'Enter the secure 6-digit code sent to your company email.' : 'Sign in to your TPZ ERP account' }}</p>
</div>