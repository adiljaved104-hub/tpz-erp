@auth
    @if (app(\App\Services\Navigation\NavigationPreferenceService::class)->workspaceModeEnabled(auth()->user()))
        <script>document.documentElement.classList.add('tpz-native-workspace');</script>
    @endif
@endauth
