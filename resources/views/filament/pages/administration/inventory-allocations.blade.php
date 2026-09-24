<x-filament-panels::page>
    @if ($canManageSettings || $canReconcile)
    <div class="grid gap-6 xl:grid-cols-2">
        @if ($canManageSettings)
        <x-filament::section heading="Allocation Policy" description="Responsibility controls visibility. Allocation controls logical stock ownership.">
            <form wire:submit="saveSettings" class="grid gap-4 sm:grid-cols-2">
                <label class="text-sm font-medium">Enforcement mode<select wire:model="enforcementMode" class="mt-1 w-full rounded-lg border-gray-300"><option value="migration_shadow">Migration / shadow</option><option value="strict">Strict</option></select></label>
                <label class="text-sm font-medium">Default GRN policy<select wire:model="defaultPolicy" class="mt-1 w-full rounded-lg border-gray-300"><option value="automatic">Automatic Allocation</option><option value="ask_at_grn">Ask at GRN</option><option value="no_automatic">No automatic allocation</option></select></label>
                @error('enforcementMode') <p class="text-sm text-danger-600 sm:col-span-2">{{ $message }}</p> @enderror
                <x-filament::button type="submit">Save Allocation Settings</x-filament::button>
            </form>
        </x-filament::section>
        @endif
        @if ($canReconcile)
        <x-filament::section heading="Reconcile Legacy Stock" description="Explicitly transfer System / Unallocated stock to an Employee or Team. This does not change physical inventory.">
            <form wire:submit="reconcile" class="grid gap-3 sm:grid-cols-2">
                <select wire:model="inventoryId" class="rounded-lg border-gray-300"><option value="">Inventory</option>@foreach($inventories as $i)<option value="{{ $i->id }}">{{ $i->product->sku }} · {{ $i->warehouse->name }}</option>@endforeach</select>
                <select wire:model.live="targetType" class="rounded-lg border-gray-300"><option value="employee">Employee</option><option value="team">Team</option></select>
                <select wire:model="targetId" class="rounded-lg border-gray-300"><option value="">Target</option>@foreach($targetType === 'employee' ? $employees : $teams as $target)<option value="{{ $target->id }}">{{ $target->name }}</option>@endforeach</select>
                <input wire:model="quantity" type="number" min="1" class="rounded-lg border-gray-300" placeholder="Quantity">
                <textarea wire:model="reason" class="rounded-lg border-gray-300 sm:col-span-2" placeholder="Required reconciliation reason"></textarea>
                @error('quantity') <p class="text-sm text-danger-600 sm:col-span-2">{{ $message }}</p> @enderror
                <x-filament::button type="submit">Allocate Legacy Stock</x-filament::button>
            </form>
        </x-filament::section>
        @endif
    </div>
    @endif
    @if ($canReconcile && $reconciliationGaps->isNotEmpty())
    <x-filament::section heading="Physical / Allocation Reconciliation Gaps" description="These differences are shown separately and are never included in another employee's allocation.">
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr><th>Product</th><th>Warehouse</th><th>Physical Sellable</th><th>Ledger Allocated</th><th>Gap</th></tr></thead><tbody>@foreach($reconciliationGaps as $inventory)<tr><td>{{ $inventory->product->sku }}</td><td>{{ $inventory->warehouse->name }}</td><td>{{ $inventory->available_quantity }}</td><td>{{ (int) ($inventory->ledger_allocated_quantity ?? 0) }}</td><td>{{ $inventory->available_quantity - (int) ($inventory->ledger_allocated_quantity ?? 0) }}</td></tr>@endforeach</tbody></table></div>
    </x-filament::section>
    @endif
    @if ($canManageSettings)
    <x-filament::section heading="Automatic Allocation Rules" description="Rules are explicit and never derived from Responsibility or Platform.">
        <form wire:submit="createRule" class="grid gap-3 md:grid-cols-4">
            <input wire:model="ruleName" class="rounded-lg border-gray-300" placeholder="Rule name"><select wire:model="ruleAccountId" class="rounded-lg border-gray-300"><option value="">Target account</option>@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select>
            <select wire:model="ruleProductId" class="rounded-lg border-gray-300"><option value="">Any Product</option>@foreach($products as $p)<option value="{{ $p->id }}">{{ $p->sku }}</option>@endforeach</select><select wire:model="ruleBrandId" class="rounded-lg border-gray-300"><option value="">Any Brand</option>@foreach($brands as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select>
            <select wire:model="ruleCategoryId" class="rounded-lg border-gray-300"><option value="">Any Category</option>@foreach($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select><select wire:model="ruleWarehouseId" class="rounded-lg border-gray-300"><option value="">Any Warehouse</option>@foreach($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</select>
            <input wire:model="rulePriority" type="number" min="1" class="rounded-lg border-gray-300" placeholder="Priority"><x-filament::button type="submit">Create Rule</x-filament::button>
        </form>
        <div class="mt-4 space-y-2">@forelse($rules as $rule)<div class="rounded-lg border p-3 text-sm"><strong>{{ $rule->name }}</strong> → {{ $rule->targetAccount->name }} · Priority {{ $rule->priority }}</div>@empty<p class="text-sm text-gray-500">No explicit automatic allocation rules.</p>@endforelse</div>
    </x-filament::section>
    @endif
    <x-filament::section heading="Allocation Balances">
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr><th>Account</th><th>Product</th><th>Warehouse</th><th>Allocated</th><th>Reserved</th><th>Available</th></tr></thead><tbody>@foreach($balances as $b)<tr><td>{{ $b->account->name }}</td><td>{{ $b->inventory->product->sku }}</td><td>{{ $b->inventory->warehouse->name }}</td><td>{{ $b->allocated_quantity }}</td><td>{{ $b->reserved_quantity }}</td><td>{{ $b->availableQuantity() }}</td></tr>@endforeach</tbody></table></div>
    </x-filament::section>
    <x-filament::section heading="Immutable Allocation Events" description="Latest allocation, reservation, reconciliation, receipt and consumption events.">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[64rem] text-sm">
                <thead><tr><th>Time</th><th>Event</th><th>Product</th><th>Warehouse</th><th>From</th><th>To</th><th>Qty</th><th>Actor</th><th>Reason</th></tr></thead>
                <tbody>
                    @forelse ($events as $event)
                        <tr>
                            <td class="whitespace-nowrap">{{ $event->created_at?->format('d M Y h:i A') }}</td>
                            <td>{{ str($event->event_type)->replace('_', ' ')->title() }}</td>
                            <td>{{ $event->inventory?->product?->sku ?? '—' }}</td>
                            <td>{{ $event->inventory?->warehouse?->name ?? '—' }}</td>
                            <td>{{ $event->fromAccount?->name ?? '—' }}</td>
                            <td>{{ $event->toAccount?->name ?? '—' }}</td>
                            <td>{{ $event->quantity }}</td>
                            <td>{{ $event->actor?->name ?? 'System' }}</td>
                            <td class="max-w-80 whitespace-normal break-words">{{ $event->reason }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="py-6 text-center text-gray-500">No allocation events recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
