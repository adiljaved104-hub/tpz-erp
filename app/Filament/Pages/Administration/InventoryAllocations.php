<?php

namespace App\Filament\Pages\Administration;

use App\Enums\EmployeeRole;
use App\Enums\InventoryAllocationMode;
use App\Enums\InventoryAllocationPolicy;
use App\Enums\InventoryPermission;
use App\Filament\Concerns\HandlesActionFeedback;
use App\Models\Employee;
use App\Models\InventoryAllocationAccount;
use App\Models\InventoryAllocationBalance;
use App\Models\InventoryAllocationEvent;
use App\Models\InventoryAllocationSetting;
use App\Models\ProductInventory;
use App\Models\Team;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Inventory\InventoryAllocationPolicyService;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Responsibilities\ResponsibilityReadService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryAllocations extends Page
{
    use HandlesActionFeedback;

    protected string $view = 'filament.pages.administration.inventory-allocations';

    protected static ?string $slug = 'administration/inventory-allocations';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Inventory Allocations';

    public string $enforcementMode = '';

    public string $defaultPolicy = '';

    public string $inventorySearch = '';

    /** @var array<int, int> */
    public array $selectedInventoryIds = [];

    /** @var array<int, int|string> */
    public array $allocationQuantities = [];

    public string $targetType = 'employee';

    public ?int $targetId = null;

    public string $targetSearch = '';

    public string $targetLabel = '';

    public string $reason = '';

    public string $balanceSearch = '';

    public string $eventSearch = '';

    public string $eventTypeFilter = '';

    public string $eventDateFilter = '';

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(InventoryAuthorization::class)->allows($actor, InventoryPermission::ViewAllocations);
    }

    public function mount(InventoryAllocationPolicyService $policy): void
    {
        abort_unless(static::canAccess(), 403);
        if (! $this->globalAdministrationAllowed()) {
            return;
        }
        $settings = $policy->settings();
        $this->enforcementMode = $settings->enforcement_mode->value;
        $this->defaultPolicy = $settings->default_policy->value;
    }

    public function saveSettings(): void
    {
        abort_unless($this->globalAdministrationAllowed(), 403);
        app(InventoryAuthorization::class)->authorize(auth()->user(), InventoryPermission::ManageAllocationSettings);
        $data = $this->validate([
            'enforcementMode' => ['required', Rule::enum(InventoryAllocationMode::class)],
            'defaultPolicy' => ['required', Rule::enum(InventoryAllocationPolicy::class)],
        ]);
        if ($data['enforcementMode'] === InventoryAllocationMode::Strict->value) {
            $system = app(InventoryAllocationService::class)->systemAccount();
            if (InventoryAllocationBalance::query()->where('account_id', $system->id)->where('allocated_quantity', '>', 0)->exists()) {
                throw ValidationException::withMessages(['enforcementMode' => 'Reconcile all System / Unallocated stock before enabling strict mode.']);
            }
            $hasGap = ProductInventory::query()
                ->withSum('allocationBalances as ledger_allocated_quantity', 'allocated_quantity')
                ->get()
                ->contains(fn (ProductInventory $inventory): bool => (int) $inventory->available_quantity !== (int) ($inventory->ledger_allocated_quantity ?? 0));
            if ($hasGap) {
                throw ValidationException::withMessages(['enforcementMode' => 'Resolve all physical / allocation reconciliation gaps before enabling strict mode.']);
            }
        }
        InventoryAllocationSetting::query()->updateOrCreate(['singleton_key' => 'inventory_allocation'], [
            'id' => 1,
            'enforcement_mode' => $data['enforcementMode'], 'default_policy' => $data['defaultPolicy'],
            'updated_by_user_id' => auth()->id(),
        ]);
        Notification::make()->success()->title('Allocation settings saved')->send();
    }

    public function updatedTargetType(): void
    {
        $this->reset('targetId', 'targetSearch', 'targetLabel');
    }

    public function addInventory(int $inventoryId): void
    {
        abort_unless($this->globalAdministrationAllowed(), 403);
        app(InventoryAuthorization::class)->authorize(auth()->user(), InventoryPermission::ManageAllocations);

        $inventory = $this->allocatableInventoryQuery()
            ->whereKey($inventoryId)
            ->first();

        if (! $inventory instanceof ProductInventory) {
            throw ValidationException::withMessages([
                'inventorySearch' => 'This inventory no longer has unassigned stock available.',
            ]);
        }

        if (! in_array($inventoryId, $this->selectedInventoryIds, true)) {
            $this->selectedInventoryIds[] = $inventoryId;
            $this->allocationQuantities[$inventoryId] = 1;
        }

        $this->reset('inventorySearch');
        $this->resetValidation('inventorySearch');
    }

    public function removeInventory(int $inventoryId): void
    {
        $this->selectedInventoryIds = array_values(array_filter(
            $this->selectedInventoryIds,
            fn (int $selectedId): bool => $selectedId !== $inventoryId,
        ));
        unset($this->allocationQuantities[$inventoryId]);
        $this->resetValidation("allocationQuantities.{$inventoryId}");
    }

    public function selectTarget(int $targetId): void
    {
        abort_unless($this->globalAdministrationAllowed(), 403);
        app(InventoryAuthorization::class)->authorize(auth()->user(), InventoryPermission::ManageAllocations);

        $target = $this->targetSearchQuery()->whereKey($targetId)->first();
        if ($target === null) {
            throw ValidationException::withMessages(['targetSearch' => 'Select an active employee or team.']);
        }

        $this->targetId = $targetId;
        $this->targetLabel = $this->targetType === 'employee'
            ? $this->employeeLabel($target)
            : $target->name;
        $this->reset('targetSearch');
        $this->resetValidation('targetId', 'targetSearch');
    }

    public function clearTarget(): void
    {
        $this->reset('targetId', 'targetSearch', 'targetLabel');
    }

    public function allocateStock(): void
    {
        $this->runWithActionFeedback(function (): void {
            abort_unless($this->globalAdministrationAllowed(), 403);
            app(InventoryAuthorization::class)->authorize(auth()->user(), InventoryPermission::ManageAllocations);
            $data = $this->validate([
                'selectedInventoryIds' => ['required', 'array', 'min:1', 'max:100'],
                'selectedInventoryIds.*' => ['required', 'integer', 'distinct', 'exists:product_inventories,id'],
                'allocationQuantities' => ['required', 'array'],
                'targetType' => ['required', 'in:employee,team'],
                'targetId' => [
                    'required',
                    'integer',
                    Rule::exists($this->targetType === 'team' ? 'teams' : 'employees', 'id')->where('status', true),
                ],
                'reason' => ['required', 'string', 'min:5', 'max:2000'],
            ], [
                'selectedInventoryIds.required' => 'Please select at least one product.',
                'selectedInventoryIds.min' => 'Please select at least one product.',
                'selectedInventoryIds.max' => 'Select no more than 100 products in one allocation.',
                'selectedInventoryIds.*.exists' => 'One selected product is no longer available. Remove it and try again.',
                'allocationQuantities.required' => 'Enter a quantity for each selected product.',
                'targetId.required' => 'Please select an employee or team.',
                'targetId.exists' => 'The selected employee or team is no longer active.',
                'reason.required' => 'Please provide a reason before continuing.',
                'reason.min' => 'Please provide a reason of at least 5 characters.',
            ]);

            $quantities = [];
            foreach ($data['selectedInventoryIds'] as $inventoryId) {
                $quantity = $data['allocationQuantities'][$inventoryId] ?? null;
                if (filter_var($quantity, FILTER_VALIDATE_INT) === false || (int) $quantity < 1) {
                    $inventory = ProductInventory::query()->with('product:id,sku,name')->find($inventoryId);
                    $label = $inventory?->product === null
                        ? "Inventory #{$inventoryId}"
                        : "{$inventory->product->sku} — {$inventory->product->name}";

                    throw ValidationException::withMessages([
                        "allocationQuantities.{$inventoryId}" => "{$label} — Enter a quantity of at least 1.",
                    ]);
                }
                $quantities[(int) $inventoryId] = (int) $quantity;
            }

            $account = $data['targetType'] === 'employee'
                ? app(InventoryAllocationService::class)->employeeAccount($data['targetId'])
                : app(InventoryAllocationService::class)->teamAccount($data['targetId']);
            app(InventoryAllocationService::class)->reconcileMany($quantities, $account, auth()->user(), $data['reason']);
            $recipient = $this->allocationAccountLabel($account);
            $this->reset('selectedInventoryIds', 'allocationQuantities', 'targetId', 'targetSearch', 'targetLabel', 'reason');
            Notification::make()->success()->title("Stock allocated successfully to {$recipient}.")->send();
        }, 'Stock was not allocated');
    }

    public function getViewData(): array
    {
        $actor = auth()->user();
        $global = $this->globalAdministrationAllowed();
        $inventoryIds = $global ? null : app(ResponsibilityReadService::class)->myInventory($actor)->pluck('inventory_id')->filter()->values();
        $balances = InventoryAllocationBalance::query()->with(['account.employee', 'account.team', 'inventory.product', 'inventory.warehouse']);
        $events = InventoryAllocationEvent::query()->with(['inventory.product', 'inventory.warehouse', 'fromAccount.employee', 'fromAccount.team', 'toAccount.employee', 'toAccount.team', 'actor']);
        if ($inventoryIds !== null) {
            $balances->whereIn('product_inventory_id', $inventoryIds);
            $events->whereIn('product_inventory_id', $inventoryIds);
        }
        $eventTypes = (clone $events)->withoutEagerLoads()->reorder()->distinct()->orderBy('event_type')->pluck('event_type');
        if ($search = trim($this->balanceSearch)) {
            $balances->where(function (Builder $query) use ($search): void {
                $query->whereHas('account', fn (Builder $account): Builder => $account->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('inventory.product', fn (Builder $product): Builder => $this->applyProductSearch($product, $search))
                    ->orWhereHas('inventory.warehouse', fn (Builder $warehouse): Builder => $warehouse->where('name', 'like', "%{$search}%"));
            });
        }
        if ($search = trim($this->eventSearch)) {
            $events->where(function (Builder $query) use ($search): void {
                $query->whereHas('inventory.product', fn (Builder $product): Builder => $this->applyProductSearch($product, $search))
                    ->orWhereHas('fromAccount', fn (Builder $account): Builder => $account->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('toAccount', fn (Builder $account): Builder => $account->where('name', 'like', "%{$search}%"));
            });
        }
        if ($this->eventTypeFilter !== '') {
            $events->where('event_type', $this->eventTypeFilter);
        }
        if ($this->eventDateFilter !== '') {
            $events->whereDate('created_at', $this->eventDateFilter);
        }

        $reconciliationGaps = collect();
        if ($global) {
            $reconciliationGaps = ProductInventory::query()
                ->with(['product', 'warehouse'])
                ->withSum('allocationBalances as ledger_allocated_quantity', 'allocated_quantity')
                ->get()
                ->filter(fn (ProductInventory $inventory): bool => (int) $inventory->available_quantity !== (int) ($inventory->ledger_allocated_quantity ?? 0))
                ->values();
        }

        return [
            'balances' => $balances->latest('id')->limit(100)->get(),
            'events' => $events->latest('id')->limit(50)->get(),
            'canManageSettings' => $global && app(InventoryAuthorization::class)->allows($actor, InventoryPermission::ManageAllocationSettings),
            'canReconcile' => $global && app(InventoryAuthorization::class)->allows($actor, InventoryPermission::ManageAllocations),
            'inventorySearchResults' => $global ? $this->inventorySearchResults() : collect(),
            'selectedInventories' => $global ? $this->selectedInventories() : collect(),
            'targetSearchResults' => $global ? $this->targetSearchResults() : collect(),
            'eventTypes' => $eventTypes,
            'reconciliationGaps' => $reconciliationGaps,
        ];
    }

    public function allocationAccountLabel(?InventoryAllocationAccount $account): string
    {
        if ($account === null) {
            return '—';
        }
        if ($account->is_system) {
            return 'Unassigned Stock';
        }
        if ($account->employee !== null) {
            return $this->employeeLabel($account->employee);
        }

        return $account->team?->name ?? $account->name;
    }

    private function inventorySearchResults(): Collection
    {
        $search = trim($this->inventorySearch);
        if (mb_strlen($search) < 2) {
            return collect();
        }

        return $this->allocatableInventoryQuery()
            ->whereHas('product', fn (Builder $query): Builder => $this->applyProductSearch($query, $search))
            ->orderBy('id')
            ->limit(20)
            ->get();
    }

    private function selectedInventories(): Collection
    {
        if ($this->selectedInventoryIds === []) {
            return collect();
        }

        $systemId = InventoryAllocationAccount::query()->where('identity_key', 'system')->value('id');

        return ProductInventory::query()
            ->with([
                'product.brandRelation',
                'warehouse',
                'allocationBalances' => fn ($query) => $query->where('account_id', $systemId),
            ])
            ->whereKey($this->selectedInventoryIds)
            ->get()
            ->sortBy(fn (ProductInventory $inventory): int => array_search($inventory->id, $this->selectedInventoryIds, true))
            ->values();
    }

    private function allocatableInventoryQuery(): Builder
    {
        $systemId = InventoryAllocationAccount::query()->where('identity_key', 'system')->value('id');

        return ProductInventory::query()
            ->with([
                'product.brandRelation',
                'warehouse',
                'allocationBalances' => fn ($query) => $query->where('account_id', $systemId),
            ])
            ->whereHas('allocationBalances', fn (Builder $query): Builder => $query
                ->where('account_id', $systemId)
                ->whereColumn('allocated_quantity', '>', 'reserved_quantity'));
    }

    private function targetSearchResults(): Collection
    {
        $search = trim($this->targetSearch);
        if (mb_strlen($search) < 2) {
            return collect();
        }

        return $this->targetSearchQuery()
            ->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', "%{$search}%");
                if ($this->targetType === 'employee') {
                    $query->orWhere('employee_id', 'like', "%{$search}%");
                }
            })
            ->orderBy('name')
            ->limit(20)
            ->get();
    }

    private function targetSearchQuery(): Builder
    {
        return ($this->targetType === 'team' ? Team::query() : Employee::query())->where('status', true);
    }

    private function applyProductSearch(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $product) use ($search): void {
            $product->where('sku', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhere('model', 'like', "%{$search}%")
                ->orWhere('brand', 'like', "%{$search}%")
                ->orWhereHas('brandRelation', fn (Builder $brand): Builder => $brand->where('name', 'like', "%{$search}%"));
        });
    }

    private function employeeLabel(Employee $employee): string
    {
        return trim("{$employee->employee_id} — {$employee->name}", ' —');
    }

    private function globalAdministrationAllowed(): bool
    {
        return in_array(auth()->user()?->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true);
    }
}
