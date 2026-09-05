@extends('auth.layout')

@section('content')
    <h2 class="text-lg font-semibold">Verification Code</h2>
    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
        Enter the 6-digit code sent to your company email.<br>
        It expires in {{ $expiryMinutes }} minutes.
    </p>
    <form method="POST" action="{{ $mode === 'login' ? route('auth.otp.verify.submit') : route('auth.password.verify.submit') }}" class="mt-6 space-y-5">
        @csrf
        <x-auth.otp-code-input :show-label="false" />
        <x-filament::button type="submit" class="tpz-auth-submit" style="width:100%;justify-content:center">Verify</x-filament::button>
    </form>
    <x-auth.otp-resend-countdown
        :seconds="$resendSeconds"
        :route="$mode === 'login' ? route('auth.otp.resend') : route('auth.password.resend')"
        :component-key="$resendKey"
    />
    <p class="mt-6 text-sm" style="text-align:center"><x-filament::link :href="route('filament.admin.auth.login')">Back to Password Login</x-filament::link></p>
@endsection
