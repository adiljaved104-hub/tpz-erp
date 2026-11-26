@extends('auth.layout')

@section('content')
    <div class="tpz-auth-form-heading">
        <h2>Enter Verification Code</h2>
        <p>A 6-digit code has been sent to your {{ $mode === 'login' ? 'company email' : 'password recovery email' }}.
            @if(filled($maskedEmail ?? null))
                <span class="tpz-auth-email">{{ $maskedEmail }}</span>
            @endif
        </p>
    </div>
    <form method="POST" action="{{ $mode === 'login' ? route('auth.otp.verify.submit') : route('auth.password.verify.submit') }}" class="tpz-auth-otp-form">
        @csrf
        <x-auth.otp-code-input :show-label="false" />
        <p class="tpz-auth-otp-note">This code expires in {{ $expiryMinutes }} minutes.</p>
        <x-auth.otp-resend-countdown
            :seconds="$resendSeconds"
            :route="$mode === 'login' ? route('auth.otp.resend') : route('auth.password.resend')"
            :component-key="$resendKey"
        />
        <x-filament::button type="submit" class="tpz-auth-submit" style="width:100%;justify-content:center">✓ &nbsp; Verify Code</x-filament::button>
    </form>
    <p class="tpz-auth-link">
        <x-filament::link :href="route('filament.admin.auth.login')">← Back to Login</x-filament::link>
    </p>
@endsection
