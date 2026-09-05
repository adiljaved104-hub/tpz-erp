@extends('auth.layout')

@section('content')
    <h2 class="text-lg font-semibold">Create a new password</h2>
    <p class="mt-1 text-sm text-gray-500">Use a strong password that you do not use elsewhere.</p>
    <form method="POST" action="{{ route('auth.password.update') }}" class="mt-5 space-y-4">
        @csrf
        <div><label for="password" class="mb-1 block text-sm font-medium">New Password</label><x-filament::input.wrapper :valid="!$errors->has('password')"><x-filament::input id="password" name="password" type="password" required autocomplete="new-password" /></x-filament::input.wrapper>@error('password')<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror</div>
        <div><label for="password_confirmation" class="mb-1 block text-sm font-medium">Confirm Password</label><x-filament::input.wrapper><x-filament::input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" /></x-filament::input.wrapper></div>
        <x-filament::button type="submit" class="tpz-auth-submit" style="width:100%;justify-content:center">Update Password</x-filament::button>
    </form>
@endsection
