<x-filament-panels::page>
    @if ($canManageSettings || $canReconcile)
        <div class="grid gap-6 xl:grid-cols-2">
            @if ($canManageSettings)
                <x-filament::section heading="Stock Allocation Settings" description="Choose how unassigned and newly received stock can be used.">
                    <form wire:submit="saveSettings" class="grid gap-4 sm:grid-cols-2">
                        <label class="text-sm font-medium">Allocation Mode
                            <select wire:model="enforcementMode" class="mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-900">
                                <option value="migration_shadow">Setup Mode</option>
                                <option value="strict">Controlled Mode</option>
                            </select>
                        </label>
                        <label class="text-sm font-medium">New Stock Policy
                            <select wire:model="defaultPolicy" class="mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-900">
                                <option value="automatic">Use Automatic Stock Rules</option>
                                <option value="ask_at_grn">Ask When Receiving Stock</option>
                                <option value="no_automatic">Leave as Unassigned Stock</option>
                            </select>
                        </label>
                        <div class="space-y-1 text-sm text-gray-600 dark:text-gray-400 sm:col-span-2">
                            <p><strong>Setup Mode:</strong> Existing unassigned stock can still be used while allocations are being organized.</p>
                            <p><strong>Controlled Mode:</strong> Only stock assigned to an employee or team can be used.</p>
                        </div>
                        @error('enforcementMode') <p class="text-sm text-danger-600 sm:col-span-2">{{ $message }}</p> @enderror
                        <x-filament::button type="submit">Save Stock Allocation Settings</x-filament::button>
                    </form>
                </x-filament::section>
            @endif

            @if ($canReconcile)
                <x-filament::section heading="Allocate Existing Stock" description="Assign unallocated stock to an employee or team. Physical stock quantity will not change.">
                    <form wire:submit="allocateStock" class="space-y-5">
                        <div>
                            <label class="text-sm font-medium" for="inventory-search">Find Products</label>
                            <input id="inventory-search" wire:model.live.debounce.400ms="inventorySearch" type="search" class="mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-900" placeholder="Search by SKU, product, brand or model (2+ characters)" autocomplete="off">
                            @error('inventorySearch') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                            @if (mb_strlen(trim($inventorySearch)) >= 2)
                                <div class="mt-2 max-h-64 divide-y overflow-y-auto rounded-lg border border-gray-200 dark:divide-white/10 dark:border-white/10">
                                    @forelse ($inventorySearchResults as $inventory)
                                        @php($unassigned = $inventory->allocationBalances->first()?->availableQuantity() ?? 0)
                                        <button type="button" wire:click="addInventory({{ $inventory->id }})" class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/5">
                                            <span class="font-medium">{{ $inventory->product->sku }} — {{ $inventory->product->name }}</span>
                                            <span class="block text-gray-500 dark:text-gray-400">{{ $inventory->warehouse->name }} — Unassigned: {{ $unassigned }}</span>
                                        </button>
                                    @empty
                                        <p class="px-3 py-4 text-sm text-gray-500">No allocatable unassigned stock matches this search.</p>
                                    @endforelse
                                </div>
                            @endif
                        </div>

                        @error('selectedInventoryIds') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                        @if ($selectedInventories->isNotEmpty())
                            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                                <table class="w-full min-w-[44rem] text-sm">
                                    <thead class="bg-gray-50 text-left dark:bg-white/5"><tr><th class="px-3 py-2">Product</th><th class="px-3 py-2">Warehouse</th><th class="px-3 py-2 text-right">Unassigned</th><th class="px-3 py-2">Qty to Allocate</th><th class="px-3 py-2"><span class="sr-only">Remove</span></th></tr></thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                                        @foreach ($selectedInventories as $inventory)
                                            @php($unassigned = $inventory->allocationBalances->first()?->availableQuantity() ?? 0)
                                            <tr wire:key="allocation-inventory-{{ $inventory->id }}">
                                                <td class="px-3 py-3"><span class="font-medium">{{ $inventory->product->name }}</span><span class="block text-xs text-gray-500">{{ $inventory->product->sku }}@if($inventory->product->model) · {{ $inventory->product->model }}@endif</span></td>
                                                <td class="px-3 py-3">{{ $inventory->warehouse->name }}</td>
                                                <td class="px-3 py-3 text-right tabular-nums">{{ $unassigned }}</td>
                                                <td class="px-3 py-3">
                                                    <input wire:model.live.debounce.300ms="allocationQuantities.{{ $inventory->id }}" type="number" min="1" max="{{ $unassigned }}" class="w-28 rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-900" aria-label="Quantity to allocate for {{ $inventory->product->name }}">
                                                    @error("allocationQuantities.{$inventory->id}") <p class="mt-1 text-xs text-danger-600">{{ $message }}</p> @enderror
                                                </td>
                                                <td class="px-3 py-3 text-right"><button type="button" wire:click="removeInventory({{ $inventory->id }})" class="text-sm font-medium text-danger-600 hover:underline">Remove</button></td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        <fieldset class="space-y-3">
                            <legend class="text-sm font-medium">Give Stock To</legend>
                            <div class="flex gap-4">
                                <label class="inline-flex items-center gap-2"><input wire:model.live="targetType" type="radio" value="employee"> Employee</label>
                                <label class="inline-flex items-center gap-2"><input wire:model.live="targetType" type="radio" value="team"> Team</label>
                            </div>
                            <label class="block text-sm font-medium" for="target-search">{{ $targetType === 'employee' ? 'Select Employee' : 'Select Team' }}</label>
                            @if ($targetId && $targetLabel)
                                <div class="flex items-center justify-between rounded-lg border border-primary-200 bg-primary-50 px-3 py-2 text-sm dark:border-primary-500/30 dark:bg-primary-500/10">
                                    <span class="font-medium">{{ $targetLabel }}</span>
                                    <button type="button" wire:click="clearTarget" class="text-primary-700 hover:underline dark:text-primary-300">Change</button>
                                </div>
                            @else
                                <input id="target-search" wire:model.live.debounce.400ms="targetSearch" type="search" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-900" placeholder="Search by {{ $targetType === 'employee' ? 'employee ID or name' : 'team name' }}" autocomplete="off">
                                @if (mb_strlen(trim($targetSearch)) >= 2)
                                    <div class="max-h-48 divide-y overflow-y-auto rounded-lg border border-gray-200 dark:divide-white/10 dark:border-white/10">
                                        @forelse ($targetSearchResults as $target)
                                            <button type="button" wire:click="selectTarget({{ $target->id }})" class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/5">{{ $targetType === 'employee' ? trim("{$target->employee_id} — {$target->name}", ' —') : $target->name }}</button>
                                        @empty
                                            <p class="px-3 py-4 text-sm text-gray-500">No active {{ $targetType }} matches this search.</p>
                                        @endforelse
                                    </div>
                                @endif
                            @endif
                            @error('targetId') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                            @error('targetSearch') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                        </fieldset>

                        <label class="block text-sm font-medium">Reason
                            <textarea wire:model.live.debounce.400ms="reason" rows="3" class="mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-900" placeholder="Example: Initial stock allocation"></textarea>
                        </label>
                        @error('reason') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror

                        @if ($selectedInventories->isNotEmpty())
                            <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm dark:border-white/10 dark:bg-white/5">
                                <p class="font-medium">Confirm allocation</p>
                                <p class="mt-2 text-gray-600 dark:text-gray-400">Allocate:</p>
                                <ul class="list-inside list-disc">@foreach ($selectedInventories as $inventory)<li>{{ $allocationQuantities[$inventory->id] ?? 1 }} × {{ $inventory->product->name }} <span class="text-gray-500">({{ $inventory->product->sku }})</span></li>@endforeach</ul>
                                <p class="mt-2"><span class="text-gray-500">To:</span> {{ $targetLabel ?: 'Not selected' }}</p>
                                <p><span class="text-gray-500">Reason:</span> {{ $reason ?: 'Not entered' }}</p>
                            </div>
                        @endif

                        <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="allocateStock">
                            <span wire:loading.remove wire:target="allocateStock">Allocate Stock</span>
                            <span wire:loading wire:target="allocateStock">Allocating...</span>
                        </x-filament::button>
                    </form>
                </x-filament::section>
            @endif
        </div>
    @endif

    @if ($canReconcile && $reconciliationGaps->isNotEmpty())
        <x-filament::section heading="Physical / Allocation Reconciliation Gaps" description="These differences are shown separately and are never included in another employee's allocation.">
            <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr><th>Product</th><th>Warehouse</th><th>Physical Sellable</th><th>Ledger Allocated</th><th>Gap</th></tr></thead><tbody>@foreach($reconciliationGaps as $inventory)<tr><td>{{ $inventory->product->sku }} — {{ $inventory->product->name }}</td><td>{{ $inventory->warehouse->name }}</td><td>{{ $inventory->available_quantity }}</td><td>{{ (int) ($inventory->ledger_allocated_quantity ?? 0) }}</td><td>{{ $inventory->available_quantity - (int) ($inventory->ledger_allocated_quantity ?? 0) }}</td></tr>@endforeach</tbody></table></div>
        </x-filament::section>
    @endif

    @if ($canManageSettings)
        <x-filament::section heading="Automatic Stock Rules" description="Automatically assign newly received stock when it matches a rule.">
            <form wire:submit="createRule" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <label class="text-sm font-medium">Rule Name<input wire:model="ruleName" class="mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-900" placeholder="Example: Dell laptops to E-Commerce"></label>
                <div class="relative">
                    <label class="text-sm font-medium">Give Stock To<input wire:model.live.debounce.400ms="ruleAccountSearch" class="mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-900" placeholder="Search employee or team"></label>
                    @if ($ruleAccountLabel)<p class="mt-1 text-xs text-primary-600">Selected: {{ $ruleAccountLabel }}</p>@endif
                    @if (mb_strlen(trim($ruleAccountSearch)) >= 2)<div class="absolute z-10 mt-1 max-h-48 w-full divide-y overflow-y-auto rounded-lg border bg-white shadow-lg dark:divide-white/10 dark:border-white/10 dark:bg-gray-900">@foreach($ruleAccountSearchResults as $account)<button type="button" wire:click="selectRuleAccount({{ $account->id }})" class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/5">{{ $this->allocationAccountLabel($account) }}</button>@endforeach</div>@endif
                </div>
                <div class="relative">
                    <label class="text-sm font-medium">Product <span class="font-normal text-gray-500">(optional)</span><input wire:model.live.debounce.400ms="ruleProductSearch" class="mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-900" placeholder="Search SKU or product"></label>
                    @if ($ruleProductLabel)<p class="mt-1 text-xs text-primary-600">Selected: {{ $ruleProductLabel }}</p>@endif
                    @if (mb_strlen(trim($ruleProductSearch)) >= 2)<div class="absolute z-10 mt-1 max-h-48 w-full divide-y overflow-y-auto rounded-lg border bg-white shadow-lg dark:divide-white/10 dark:border-white/10 dark:bg-gray-900">@foreach($ruleProductSearchResults as $product)<button type="button" wire:click="selectRuleProduct({{ $product->id }})" class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/5">{{ $product->sku }} — {{ $product->name }}</button>@endforeach</div>@endif
                </div>
                <label class="text-sm font-medium">Brand<select wire:model="ruleBrandId" class="mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-900"><option value="">Any Brand</option>@foreach($brands as $brand)<option value="{{ $brand->id }}">{{ $brand->name }}</option>@endforeach</select></label>
                <label class="text-sm font-medium">Category<select wire:model="ruleCategoryId" class="mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-900"><option value="">Any Category</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></label>
                <label class="text-sm font-medium">Warehouse<select wire:model="ruleWarehouseId" class="mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-900"><option value="">Any Warehouse</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>@endforeach</select></label>
                <label class="text-sm font-medium">Priority<input wire:model="rulePriority" type="number" min="1" class="mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-900"></label>
                <div class="flex items-end"><x-filament::button type="submit">Create Rule</x-filament::button></div>
            </form>
            <div class="mt-4 space-y-2">@forelse($rules as $rule)<div class="rounded-lg border p-3 text-sm dark:border-white/10"><strong>{{ $rule->name }}</strong> → {{ $this->allocationAccountLabel($rule->targetAccount) }} · Priority {{ $rule->priority }}</div>@empty<p class="text-sm text-gray-500">No automatic stock rules configured.</p>@endforelse</div>
        </x-filament::section>
    @endif

    <x-filament::section heading="Allocation Balances">
        <input wire:model.live.debounce.400ms="balanceSearch" type="search" class="mb-4 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-900" placeholder="Search account, SKU, product, model or warehouse">
        <div class="overflow-x-auto"><table class="w-full min-w-[56rem] text-sm"><thead><tr><th>Account</th><th>SKU</th><th>Product Name</th><th>Warehouse</th><th class="text-right">Allocated</th><th class="text-right">Reserved</th><th class="text-right">Available</th></tr></thead><tbody>@forelse($balances as $balance)<tr><td>{{ $this->allocationAccountLabel($balance->account) }}</td><td>{{ $balance->inventory->product->sku }}</td><td>{{ $balance->inventory->product->name }}</td><td>{{ $balance->inventory->warehouse->name }}</td><td class="text-right tabular-nums">{{ $balance->allocated_quantity }}</td><td class="text-right tabular-nums">{{ $balance->reserved_quantity }}</td><td class="text-right tabular-nums">{{ $balance->availableQuantity() }}</td></tr>@empty<tr><td colspan="7" class="py-6 text-center text-gray-500">No allocation balances match the current search.</td></tr>@endforelse</tbody></table></div>
    </x-filament::section>

    <x-filament::section heading="Immutable Allocation Events" description="Latest allocation, reservation, reconciliation, receipt and consumption events.">
        <div class="mb-4 grid gap-3 md:grid-cols-3">
            <input wire:model.live.debounce.400ms="eventSearch" type="search" class="rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-900" placeholder="Search product or account">
            <select wire:model.live="eventTypeFilter" class="rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-900"><option value="">All Event Types</option>@foreach($eventTypes as $eventType)<option value="{{ $eventType }}">{{ str($eventType)->replace('_', ' ')->title() }}</option>@endforeach</select>
            <input wire:model.live="eventDateFilter" type="date" class="rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-900" aria-label="Event date">
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[72rem] text-sm">
                <thead><tr><th>Time</th><th>Event</th><th>Product</th><th>Warehouse</th><th>From</th><th>To</th><th>Qty</th><th>Actor</th><th>Reason</th></tr></thead>
                <tbody>
                    @forelse ($events as $event)
                        <tr>
                            <td class="whitespace-nowrap">{{ $event->created_at?->format('d M Y h:i A') }}</td>
                            <td>{{ str($event->event_type)->replace('_', ' ')->title() }}</td>
                            <td><span class="font-medium">{{ $event->inventory?->product?->name ?? '—' }}</span><span class="block text-xs text-gray-500">{{ $event->inventory?->product?->sku ?? '—' }}</span></td>
                            <td>{{ $event->inventory?->warehouse?->name ?? '—' }}</td>
                            <td>{{ $this->allocationAccountLabel($event->fromAccount) }}</td>
                            <td>{{ $this->allocationAccountLabel($event->toAccount) }}</td>
                            <td class="tabular-nums">{{ $event->quantity }}</td>
                            <td>{{ $event->actor?->name ?? 'System' }}</td>
                            <td class="max-w-80 whitespace-normal break-words">{{ $event->reason }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="py-6 text-center text-gray-500">No allocation events match the current filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
