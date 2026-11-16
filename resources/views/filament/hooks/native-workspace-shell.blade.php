@auth
    @if (app(\App\Services\Navigation\NavigationPreferenceService::class)->workspaceModeEnabled(auth()->user()))
        <script>
            document.documentElement.classList.add('tpz-native-workspace');

            // Filament owns the existing 'theme' preference and user menu.
            // Re-apply its stored choice on Livewire navigation, which can
            // replace the html class even when the choice is still persisted.
            // Never store another theme key or override the user's selection.
            if (!window.__tpzNativeThemeNavigationBound) {
                window.__tpzNativeThemeNavigationBound = true;
                const applySavedFilamentTheme = () => {
                    const choice = localStorage.getItem('theme');
                    if (!['light', 'dark', 'system'].includes(choice)) return;
                    const dark = choice === 'dark'
                        || (choice === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                    document.documentElement.classList.toggle('dark', dark);
                };
                document.addEventListener('livewire:navigated', () => {
                    requestAnimationFrame(applySavedFilamentTheme);
                });
                requestAnimationFrame(applySavedFilamentTheme);
            }
        </script>
    @endif
@endauth
