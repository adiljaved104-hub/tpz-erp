<x-filament-panels::page>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['Listings monitored', $listings_monitored, 'primary'],
            ['Featured Offer held', $featured_offer_held, 'success'],
            ['Featured Offer lost', $featured_offer_lost, 'danger'],
            ['Active stock exposure', $active_stock_exposure, 'warning'],
            ['Regained (24h)', $featured_offer_regained, 'success'],
            ['Unacknowledged', $unacknowledged, 'warning'],
            ['Escalated', $escalated, 'danger'],
            ['Source failures', $source_failures, 'gray'],
        ] as [$label, $value, $color])
            <x-filament::section compact>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</p>
                <p class="mt-1 text-2xl font-semibold">{{ number_format($value) }}</p>
                <x-filament::badge :color="$color" class="mt-2 w-fit">{{ $value > 0 ? 'Requires review' : 'Clear' }}</x-filament::badge>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section heading="Operational status" description="Unknown source state is never treated as Featured Offer lost.">
        <p class="text-sm">Latest successful check: {{ $latest_successful_check ? \Illuminate\Support\Carbon::parse($latest_successful_check)->format('d M Y, h:i A') : 'No successful observations yet' }}</p>
    </x-filament::section>

    <x-filament::section heading="Marketplace accounts & connections" description="Each platform can have New, Renewed, or other accounts with ordered API, Browser, Email, or Feed connections.">
        <div class="space-y-4">
            @forelse ($accounts as $account)
                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div><strong>{{ $account->platform->name }} · {{ $account->name }}</strong><div class="text-xs text-gray-500">Condition: {{ $account->product_condition ?: 'General fallback' }}</div></div>
                        <div class="flex items-center gap-2"><x-filament::badge :color="$account->enabled ? 'success' : 'gray'">{{ $account->enabled ? 'Enabled' : 'Disabled' }}</x-filament::badge>@if($canManage)<x-filament::button size="xs" color="gray" wire:click="toggleAccount({{ $account->id }})">{{ $account->enabled ? 'Disable' : 'Enable' }}</x-filament::button>@endif</div>
                    </div>
                    <div class="mt-3 grid gap-2 md:grid-cols-2">
                        @forelse ($account->connections as $connection)
                            <div class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-gray-800">
                                @php
                                    $fallbackPosition = $loop->first ? 'Primary' : 'Fallback '.($loop->iteration - 1);
                                    $healthLabel = match ($connection->health_status) {
                                        'healthy' => 'Healthy',
                                        'connected' => 'Connected',
                                        'error' => 'Error',
                                        'unavailable' => 'Unavailable',
                                        default => 'Unknown',
                                    };
                                    $healthColor = match ($connection->health_status) {
                                        'healthy', 'connected' => 'success',
                                        'error', 'unavailable' => 'danger',
                                        default => 'gray',
                                    };
                                @endphp
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <strong>{{ $connection->name }}</strong>
                                    <div class="flex flex-wrap gap-2"><x-filament::badge :color="$connection->enabled ? 'success' : 'gray'">{{ $connection->enabled ? 'Enabled' : 'Disabled' }}</x-filament::badge><x-filament::badge :color="$healthColor">{{ $healthLabel }}</x-filament::badge></div>
                                </div>
                                <dl class="mt-2 grid gap-1 text-xs text-gray-600 dark:text-gray-300 sm:grid-cols-2">
                                    <div><dt class="font-medium">Connection method</dt><dd>{{ str($connection->connection_type->value)->title() }}</dd></div>
                                    <div><dt class="font-medium">Position</dt><dd>{{ $fallbackPosition }} <span class="text-gray-400">(priority {{ $connection->priority }})</span></dd></div>
                                    <div><dt class="font-medium">Driver</dt><dd>{{ match($connection->driver) { 'amazon_sp_api' => 'Amazon SP-API', 'noon_api' => 'Noon API', 'carrefour_maf_api' => 'Carrefour / MAF API', 'sharafdg_browser' => 'Sharaf DG Browser Monitor', 'microless_browser' => 'Microless Browser Monitor', default => str($connection->driver)->replace('_', ' ')->title() } }}</dd></div>
                                    <div><dt class="font-medium">Credentials</dt><dd>{{ $this->credentialsConfigured($connection) ? 'Configured' : ($connection->driver === 'noon_api' ? 'Not Configured' : 'Missing') }}</dd></div>
                                    <div><dt class="font-medium">Last health check</dt><dd>{{ $connection->last_health_checked_at?->format('d M Y, h:i A') ?? 'Not checked yet' }}</dd></div>
                                    <div><dt class="font-medium">Last healthy</dt><dd>{{ $connection->last_healthy_at?->format('d M Y, h:i A') ?? 'Not yet' }}</dd></div>
                                    <div class="sm:col-span-2"><dt class="font-medium">Capabilities</dt><dd>{{ $connection->capabilities->pluck('capability.value')->map(fn ($capability) => str($capability)->replace('_', ' ')->title())->join(', ') ?: 'No capabilities configured' }}</dd></div>
                                </dl>
                                @if($canManage)
                                    <form wire:submit="saveConnectionConfiguration({{ $connection->id }})" class="mt-2 space-y-2 border-t border-gray-200 pt-2 dark:border-gray-700">
                                        <label class="text-xs">Fallback position<select wire:model.live="connectionSettings.{{ $connection->id }}.priority_position" class="fi-input ml-1 rounded-lg border-gray-300"><option value="primary">Primary</option><option value="fallback_1">Fallback 1</option><option value="fallback_2">Fallback 2</option><option value="custom">Advanced / custom priority</option></select></label>
                                        @if(($connectionSettings[$connection->id]['priority_position'] ?? null) === 'custom')<label class="text-xs">Custom priority <input type="number" min="1" max="65535" wire:model="connectionSettings.{{ $connection->id }}.priority" class="fi-input ml-1 w-24 rounded-lg border-gray-300"></label>@endif
                                        <div class="flex flex-wrap gap-2">@foreach(['featured_offer','orders','listing_status','stock_status','direct_product_check','product_search','event_webhook'] as $capability)<label class="text-xs"><input type="checkbox" wire:model="connectionSettings.{{ $connection->id }}.capabilities" value="{{ $capability }}"> {{ str($capability)->replace('_',' ')->title() }}</label>@endforeach</div>
                                        <x-filament::button type="submit" size="xs">Save Connection</x-filament::button>
                                    </form>
                                @endif
                                @if($canManage && $connection->driver === 'noon_api' && $connection->getRawOriginal('credential_reference') === 'noon_default')
                                    <details class="mt-3 border-t border-gray-200 pt-3 dark:border-gray-700">
                                        <summary class="cursor-pointer font-medium">Configure Noon credentials</summary>
                                        <p class="mt-2 text-xs text-gray-500">Leave Key ID, Project Code, or Private Key blank to keep the saved value. Leave Business Model blank to clear it. Saved values are never shown.</p>
                                        <form wire:submit="saveNoonCredentials({{ $connection->id }})" class="mt-2 grid gap-2 sm:grid-cols-2">
                                            <label class="text-xs">Key ID<input type="text" wire:model="noonCredentialForm.{{ $connection->id }}.key_id" autocomplete="off" maxlength="255" class="fi-input mt-1 w-full rounded-lg border-gray-300"></label>
                                            <label class="text-xs">Project Code<input type="text" wire:model="noonCredentialForm.{{ $connection->id }}.project_code" autocomplete="off" maxlength="255" class="fi-input mt-1 w-full rounded-lg border-gray-300"></label>
                                            <label class="text-xs sm:col-span-2">Private Key<textarea wire:model="noonCredentialForm.{{ $connection->id }}.private_key" autocomplete="new-password" rows="4" maxlength="20000" class="fi-input mt-1 w-full rounded-lg border-gray-300"></textarea></label>
                                            <label class="text-xs sm:col-span-2">Business Model (optional)<input type="text" wire:model="noonCredentialForm.{{ $connection->id }}.business_model" autocomplete="off" maxlength="80" class="fi-input mt-1 w-full rounded-lg border-gray-300"></label>
                                            <div class="flex flex-wrap items-center gap-2 sm:col-span-2">
                                                <x-filament::button type="submit" size="xs">Save Credentials</x-filament::button>
                                                <x-filament::button type="button" size="xs" color="gray" wire:click="testNoonConnection({{ $connection->id }})">Test Connection</x-filament::button>
                                                <span class="text-xs">Connection test: {{ $noonConnectionTestStatuses[$connection->id] ?? 'Not tested' }}</span>
                                            </div>
                                        </form>
                                    </details>
                                @endif
                                @if($canManage)<x-filament::button size="xs" color="gray" class="mt-2" wire:click="toggleConnection({{ $connection->id }})">{{ $connection->enabled ? 'Disable' : 'Enable' }}</x-filament::button>@endif
                            </div>
                        @empty
                            <p class="text-sm text-gray-500">No connection configured.</p>
                        @endforelse
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">No marketplace accounts configured.</p>
            @endforelse
        </div>
    </x-filament::section>

    @if ($canManage)
        <div class="grid gap-4 xl:grid-cols-2">
            <x-filament::section heading="Add marketplace account">
                <form wire:submit="createAccount" class="grid gap-3 sm:grid-cols-2">
                    <label class="text-sm">Platform<select wire:model="accountForm.marketplace_platform_id" required class="fi-input mt-1 w-full rounded-lg border-gray-300"><option value="">Select</option>@foreach($platforms as $platform)<option value="{{ $platform->id }}">{{ $platform->name }}</option>@endforeach</select></label>
                    <label class="text-sm">Account name<input wire:model="accountForm.name" required class="fi-input mt-1 w-full rounded-lg border-gray-300"></label>
                    <label class="text-sm">Code<input wire:model="accountForm.code" required class="fi-input mt-1 w-full rounded-lg border-gray-300" placeholder="amazon_new"></label>
                    <label class="text-sm">Condition<select wire:model="accountForm.product_condition" class="fi-input mt-1 w-full rounded-lg border-gray-300"><option value="">General</option>@foreach(['new','renewed','used','open_box','refurbished'] as $condition)<option value="{{ $condition }}">{{ str($condition)->replace('_',' ')->title() }}</option>@endforeach</select></label>
                    <x-filament::button type="submit" wire:loading.attr="disabled">Create Account</x-filament::button>
                </form>
            </x-filament::section>

            <x-filament::section heading="Add connection" description="Noon credentials can be configured securely after creating a Noon connection. Secret values are never displayed.">
                <form wire:submit="createConnection" class="grid gap-3 sm:grid-cols-2">
                    <label class="text-sm">Account<select wire:model="connectionForm.marketplace_account_id" required class="fi-input mt-1 w-full rounded-lg border-gray-300"><option value="">Select</option>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->platform->name }} · {{ $account->name }}</option>@endforeach</select></label>
                    <label class="text-sm">Name<input wire:model="connectionForm.name" required class="fi-input mt-1 w-full rounded-lg border-gray-300"></label>
                    <label class="text-sm">Connection method<select wire:model="connectionForm.connection_type" class="fi-input mt-1 w-full rounded-lg border-gray-300">@foreach(['api','browser','email','feed'] as $type)<option value="{{ $type }}">{{ str($type)->title() }}</option>@endforeach</select></label>
                    <label class="text-sm">Integration<select wire:model.live="connectionForm.driver_option" class="fi-input mt-1 w-full rounded-lg border-gray-300"><option value="amazon_sp_api">Amazon SP-API</option><option value="noon_api">Noon API</option><option value="carrefour_maf_api">Carrefour / MAF API</option><option value="sharafdg_browser">Sharaf DG Browser Monitor</option><option value="microless_browser">Microless Browser Monitor</option><option value="generic_api">Generic API</option><option value="browser_monitor">Browser Monitoring</option><option value="order_email">Order Email</option><option value="feed">Feed</option><option value="custom">Other / custom driver</option></select></label>
                    @if(($connectionForm['driver_option'] ?? null) === 'custom')<label class="text-sm">Custom driver<input wire:model="connectionForm.driver" required class="fi-input mt-1 w-full rounded-lg border-gray-300" placeholder="Custom integration driver"></label>@endif
                    <label class="text-sm">Fallback position<select wire:model.live="connectionForm.priority_position" class="fi-input mt-1 w-full rounded-lg border-gray-300"><option value="primary">Primary</option><option value="fallback_1">Fallback 1</option><option value="fallback_2">Fallback 2</option><option value="custom">Advanced / custom priority</option></select></label>
                    @if(($connectionForm['priority_position'] ?? null) === 'custom')<label class="text-sm">Custom priority<input wire:model="connectionForm.priority" type="number" min="1" max="65535" required class="fi-input mt-1 w-full rounded-lg border-gray-300"></label>@endif
                    <p class="text-xs text-gray-500 sm:col-span-2">Noon credentials can be saved below after creating a connection. Secret values are never displayed here.</p>
                    <fieldset class="sm:col-span-2"><legend class="text-sm">Capabilities</legend><div class="mt-1 flex flex-wrap gap-3">@foreach(['featured_offer','orders','listing_status','stock_status','direct_product_check','product_search','event_webhook'] as $capability)<label class="text-sm"><input type="checkbox" wire:model="connectionForm.capabilities" value="{{ $capability }}"> {{ str($capability)->replace('_',' ')->title() }}</label>@endforeach</div></fieldset>
                    <x-filament::button type="submit" wire:loading.attr="disabled">Create Connection</x-filament::button>
                </form>
            </x-filament::section>
        </div>

        <x-filament::section heading="Monitoring & notifications">
            <form wire:submit="saveSettings" class="grid gap-4 md:grid-cols-3">
                <label class="text-sm"><input type="checkbox" wire:model="settings.monitoring_enabled"> Enable Marketplace Watchdog monitoring</label>
                <label class="text-sm">Monitoring interval (minutes)<input wire:model="settings.monitoring_interval_minutes" type="number" min="5" max="1440" class="fi-input mt-1 w-full rounded-lg border-gray-300"></label>
                <label class="text-sm">Employee reminder (minutes)<input wire:model="settings.employee_reminder_minutes" type="number" min="15" max="10080" class="fi-input mt-1 w-full rounded-lg border-gray-300"></label>
                <label class="text-sm">Escalation threshold (minutes)<input wire:model="settings.escalation_threshold_minutes" type="number" min="15" max="43200" class="fi-input mt-1 w-full rounded-lg border-gray-300"></label>
                <label class="text-sm">Escalation recipients<select wire:model="settings.escalation_recipient_strategy" class="fi-input mt-1 w-full rounded-lg border-gray-300"><option value="manager_owner_admin">Matching Manager + Owner/Admin</option><option value="owner_admin">Owner/Admin only</option></select></label>
                <label class="text-sm md:col-span-2">Management summary times<input wire:model="summaryTimes" class="fi-input mt-1 w-full rounded-lg border-gray-300" placeholder="09:00, 14:00, 19:00"><span class="text-xs text-gray-500">Comma-separated 24-hour times.</span></label>
                <label class="text-sm"><input type="checkbox" wire:model="settings.acknowledgement_stops_reminders"> Acknowledgement stops employee reminders</label>
                <fieldset class="md:col-span-2"><legend class="text-sm">Escalation channels</legend><div class="mt-1 flex flex-wrap gap-4"><label><input type="checkbox" wire:model="settings.escalation_channels" value="in_app"> In-app</label><label><input type="checkbox" wire:model="settings.escalation_channels" value="email"> Email</label><label><input type="checkbox" wire:model="settings.escalation_channels" value="push"> Push</label></div></fieldset>
                <fieldset><legend class="text-sm">Event channels</legend><div class="mt-1 flex flex-wrap gap-4"><label><input type="checkbox" wire:model="settings.event_channels" value="in_app"> In-app</label><label><input type="checkbox" wire:model="settings.event_channels" value="email"> Email</label><label><input type="checkbox" wire:model="settings.event_channels" value="push"> Push</label></div></fieldset>
                <fieldset class="md:col-span-2"><legend class="text-sm">Management summary channels</legend><div class="mt-1 flex flex-wrap gap-4"><label><input type="checkbox" wire:model="settings.summary_channels" value="email"> Email</label><label><input type="checkbox" wire:model="settings.summary_channels" value="in_app"> In-app</label><label><input type="checkbox" wire:model="settings.summary_channels" value="push"> Push</label></div></fieldset>
                <div class="flex items-end"><x-filament::button type="submit" wire:loading.attr="disabled">Save Settings</x-filament::button></div>
            </form>
        </x-filament::section>
    @endif

    <x-filament::section heading="Account coverage" description="Monitored listings by marketplace platform and account.">
        <div class="flex flex-wrap gap-2">@forelse($account_breakdown as $row)<x-filament::badge color="gray">{{ $row->platform }} · {{ $row->account }}: {{ $row->listings }}</x-filament::badge>@empty<span class="text-sm text-gray-500">No monitored account listings.</span>@endforelse</div>
    </x-filament::section>

    <x-filament::section heading="Active incidents" description="Current incidents grouped by Product, Brand, Platform, Account and responsible Employee/Team.">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[760px] text-sm">
                <thead><tr class="border-b text-left"><th class="p-3">Status</th><th class="p-3">Product</th><th class="p-3">Brand</th><th class="p-3">Platform / Account</th><th class="p-3">Responsible</th><th class="p-3">Opened</th></tr></thead>
                <tbody>
                @forelse ($breakdown as $incident)
                    <tr class="border-b dark:border-gray-700"><td class="p-3"><x-filament::badge :color="$incident->incident_type === 'featured_offer_lost' ? 'danger' : 'warning'">{{ str($incident->incident_type)->replace('_', ' ')->title() }}</x-filament::badge></td><td class="p-3">{{ $incident->sku }} · {{ $incident->product }}</td><td class="p-3">{{ $incident->brand ?: '—' }}</td><td class="p-3">{{ $incident->platform }} · {{ $incident->account ?: 'Legacy/default' }}</td><td class="p-3">{{ $incident->responsible ?: ($incident->team ?: 'Owner/Admin fallback') }}</td><td class="p-3">{{ \Illuminate\Support\Carbon::parse($incident->opened_at)->format('d M Y, h:i A') }}</td></tr>
                @empty
                    <tr><td colspan="6" class="p-6 text-center text-gray-500">No active marketplace incidents.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
