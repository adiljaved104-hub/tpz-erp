@auth
    @php
        $user = auth()->user();
        $navigation = app(\App\Services\Navigation\NavigationPreferenceService::class);
        $offerNewDashboard = $navigation->eligibleForWorkspaceMode($user)
            && ! $navigation->workspaceModeEnabled($user);
    @endphp
    @if($offerNewDashboard)
        {{-- A dismissible invitation, not automatic migration. Each account
             retains Classic ERP until explicitly accepting the new UI. --}}
        <div
            x-data="{
                open: false,
                key: 'tpz-new-dashboard-offer-v1-{{ $user->id }}',
                init() {
                    if (!localStorage.getItem(this.key)) {
                        this.$nextTick(() => { this.open = true; });
                    }
                },
                dismiss() {
                    this.open = false;
                    localStorage.setItem(this.key, 'dismissed');
                },
            }"
            x-show="open"
            x-cloak
            class="tpz-native-invitation"
            role="dialog"
            aria-modal="false"
            aria-label="New Dashboard design available"
        >
            <button type="button" x-on:click="dismiss()" class="tpz-native-invitation-close" aria-label="Dismiss New Dashboard invitation">×</button>
            <div class="tpz-native-invitation-icon" aria-hidden="true">✦</div>
            <strong>New Dashboard Design Available</strong>
            <p>Try the new TPZ ERP Dashboard with unified workspaces. Your existing records and permissions stay the same.</p>
            <form method="POST" action="{{ route('erp.interface-mode') }}">
                @csrf
                <input type="hidden" name="mode" value="new">
                <button type="submit" class="tpz-native-invitation-accept">Switch to New Dashboard</button>
            </form>
            <button type="button" x-on:click="dismiss()" class="tpz-native-invitation-dismiss">Not Now</button>
        </div>
    @endif
@endauth
