@auth
    @if (auth()->user()?->employee?->status === true)
        <livewire:notification-bell />
    @endif
@endauth
