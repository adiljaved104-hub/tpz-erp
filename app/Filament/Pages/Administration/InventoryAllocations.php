<?php

namespace App\Filament\Pages\Administration;

use App\Enums\EmployeeRole;
use App\Enums\InventoryAllocationMode;
use App\Enums\InventoryAllocationPolicy;
use App\Enums\InventoryPermission;
use App\Models\Employee;
use App\Models\InventoryAllocationAccount;
use App\Models\InventoryAllocationBalance;
use App\Models\InventoryAllocationEvent;
use App\Models\InventoryAllocationRule;
use App\Models\InventoryAllocationSetting;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductInventory;
use App\Models\Team;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Inventory\InventoryAllocationPolicyService;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Responsibilities\ResponsibilityReadService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryAllocations extends Page
{
    protected string $view = 'filament.pages.administration.inventory-allocations';

    protected static ?string $slug = 'administration/inventory-allocations';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Inventory Allocations';

    public string $enforcementMode = '';

    public string $defaultPolicy = '';

    public ?int $inventoryId = null;

    public string $targetType = 'employee';

    public ?int $targetId = null;

    public int $quantity = 1;

    public string $reason = '';

    public string $ruleName = '';

    public ?int $ruleAccountId = null;

    public ?int $ruleProductId = null;

    public ?int $ruleBrandId = null;

    public ?int $ruleCategoryId = null;

    public ?int $ruleWarehouseId = null;

    public int $rulePriority = 100;

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

    public function reconcile(): void
    {
        abort_unless($this->globalAdministrationAllowed(), 403);
        app(InventoryAuthorization::class)->authorize(auth()->user(), InventoryPermission::ManageAllocations);
        $data = $this->validate([
            'inventoryId' => ['required', 'integer', 'exists:product_inventories,id'],
            'targetType' => ['required', 'in:employee,team'],
            'targetId' => [
                'required',
                'integer',
                Rule::exists($this->targetType === 'team' ? 'teams' : 'employees', 'id')->where('status', true),
            ],
            'quantity' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $account = $data['targetType'] === 'employee'
            ? app(InventoryAllocationService::class)->employeeAccount($data['targetId'])
            : app(InventoryAllocationService::class)->teamAccount($data['targetId']);
        app(InventoryAllocationService::class)->reconcile(ProductInventory::query()->findOrFail($data['inventoryId']), $account, $data['quantity'], auth()->user(), $data['reason']);
        $this->reset('inventoryId', 'targetId', 'reason');
        Notification::make()->success()->title('Legacy stock allocated')->send();
    }

    public function createRule(): void
    {
        abort_unless($this->globalAdministrationAllowed(), 403);
        app(InventoryAuthorization::class)->authorize(auth()->user(), InventoryPermission::ManageAllocationSettings);
        $data = $this->validate([
            'ruleName' => ['required', 'string', 'max:190'],
            'ruleAccountId' => [
                'required',
                Rule::exists('inventory_allocation_accounts', 'id')->where(fn ($query) => $query->where('status', true)->where('is_system', false)),
            ],
            'ruleProductId' => ['nullable', 'exists:products,id'], 'ruleBrandId' => ['nullable', 'exists:product_brands,id'],
            'ruleCategoryId' => ['nullable', 'exists:product_categories,id'], 'ruleWarehouseId' => ['nullable', 'exists:warehouses,id'],
            'rulePriority' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);
        if (collect([$data['ruleProductId'], $data['ruleBrandId'], $data['ruleCategoryId'], $data['ruleWarehouseId']])->filter()->isEmpty()) {
            throw ValidationException::withMessages(['ruleName' => 'An allocation rule needs an explicit Product, Brand, Category, or Warehouse condition.']);
        }
        InventoryAllocationRule::query()->create([
            'name' => $data['ruleName'], 'target_account_id' => $data['ruleAccountId'], 'product_id' => $data['ruleProductId'],
            'product_brand_id' => $data['ruleBrandId'], 'product_category_id' => $data['ruleCategoryId'],
            'warehouse_id' => $data['ruleWarehouseId'], 'priority' => $data['rulePriority'], 'status' => true,
            'created_by_user_id' => auth()->id(),
        ]);
        $this->reset('ruleName', 'ruleAccountId', 'ruleProductId', 'ruleBrandId', 'ruleCategoryId', 'ruleWarehouseId');
        Notification::make()->success()->title('Allocation rule created')->send();
    }

    public function getViewData(): array
    {
        $actor = auth()->user();
        $global = $this->globalAdministrationAllowed();
        $inventoryIds = $global ? null : app(ResponsibilityReadService::class)->myInventory($actor)->pluck('inventory_id')->filter()->values();
        $balances = InventoryAllocationBalance::query()->with(['account.employee', 'account.team', 'inventory.product', 'inventory.warehouse']);
        $events = InventoryAllocationEvent::query()->with(['inventory.product', 'inventory.warehouse', 'fromAccount', 'toAccount', 'actor']);
        if ($inventoryIds !== null) {
            $balances->whereIn('product_inventory_id', $inventoryIds);
            $events->whereIn('product_inventory_id', $inventoryIds);
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
            'rules' => $global ? InventoryAllocationRule::query()->with('targetAccount')->orderBy('priority')->get() : collect(),
            'accounts' => $global ? InventoryAllocationAccount::query()->where('status', true)->where('is_system', false)->orderBy('name')->get() : collect(),
            'inventories' => $global ? ProductInventory::query()->with(['product', 'warehouse'])->where('available_quantity', '>', 0)->orderBy('id')->get() : collect(),
            'employees' => $global ? Employee::query()->where('status', true)->orderBy('name')->get() : collect(),
            'teams' => $global ? Team::query()->where('status', true)->orderBy('name')->get() : collect(),
            'products' => $global ? Product::query()->products()->orderBy('name')->get() : collect(),
            'brands' => $global ? ProductBrand::query()->where('status', true)->orderBy('name')->get() : collect(),
            'categories' => $global ? ProductCategory::query()->where('status', true)->orderBy('name')->get() : collect(),
            'warehouses' => $global ? Warehouse::query()->where('status', true)->orderBy('name')->get() : collect(),
            'reconciliationGaps' => $reconciliationGaps,
        ];
    }

    private function globalAdministrationAllowed(): bool
    {
        return in_array(auth()->user()?->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true);
    }
}
