@auth
    @if(auth()->user()?->employee?->role === \App\Enums\EmployeeRole::Owner)
        @php($newInterface = app(\App\Services\Navigation\NavigationPreferenceService::class)->workspaceModeEnabled(auth()->user()))
        <form method="POST" action="{{ route('erp.interface-mode') }}" class="tpz-interface-switcher" aria-label="ERP interface selection">
            @csrf
            <input type="hidden" name="mode" value="{{ $newInterface ? 'classic' : 'new' }}">
            <span class="tpz-interface-switcher-current">{{ $newInterface ? 'New ERP' : 'Classic ERP' }}</span>
            <button type="submit" class="tpz-interface-switcher-button" aria-label="Switch to {{ $newInterface ? 'Classic ERP' : 'New ERP' }}">
                Switch to {{ $newInterface ? 'Classic' : 'New' }}
            </button>
        </form>
    @endif
@endauth
