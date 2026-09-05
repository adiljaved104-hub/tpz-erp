@extends('auth.layout')

@section('content')
    <h2 class="text-lg font-semibold text-gray-950 dark:text-white">{{ $mode === 'login' ? 'Login with Email OTP' : 'Reset your password' }}</h2>
    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $mode === 'login' ? 'Enter your company email and we’ll send you a verification code.' : 'Enter your company email and we’ll send you a password reset code.' }}</p>
    <form method="POST" action="{{ $mode === 'login' ? route('auth.otp.send') : route('auth.password.send') }}" class="mt-5 space-y-4">
        @csrf
        <div><label for="email" class="mb-1 block text-sm font-medium">Company Email</label><x-filament::input.wrapper :valid="!$errors->has('email')"><x-filament::input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email" /></x-filament::input.wrapper>@error('email')<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror</div>
        <x-filament::button type="submit" class="tpz-auth-submit" style="width:100%;justify-content:center">Send Verification Code</x-filament::button>
    </form>
    <p class="mt-5 text-sm" style="text-align:center"><x-filament::link :href="route('filament.admin.auth.login')">Back to Password Login</x-filament::link></p>
@endsection
