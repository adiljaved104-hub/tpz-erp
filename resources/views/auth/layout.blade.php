<x-filament-panels::layout.simple :has-topbar="false" max-width="md">
    <div class="fi-simple-page">
        <div class="fi-simple-page-content">
            <x-auth.branding :branding="$branding" />
            @if(session('status'))
                <div class="mb-4 rounded-lg bg-success-50 p-3 text-sm text-success-700 dark:bg-success-500/10 dark:text-success-300">{{ session('status') }}</div>
            @endif
            @yield('content')
        </div>
    </div>
</x-filament-panels::layout.simple>
