<x-filament-panels::layout.simple :has-topbar="false" max-width="md">
    @include('auth.enterprise-styles')
    <x-auth.enterprise-hero :verification="in_array(request()->route()?->getName(), ['auth.otp.verify', 'auth.password.verify'], true)" />
    <div class="fi-simple-page">
        <div class="fi-simple-page-content">
            <div class="tpz-auth-form-brand">
                <x-auth.enterprise-mark :branding="$branding" />
            </div>
            @if(session('status'))
                <div role="status" class="mb-4 rounded-lg bg-success-50 p-3 text-sm text-success-700 dark:bg-success-500/10 dark:text-success-300">{{ session('status') }}</div>
            @endif
            @yield('content')
        </div>
    </div>
</x-filament-panels::layout.simple>
