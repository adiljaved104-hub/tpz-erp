<?php

namespace App\Livewire;

use App\Enums\EmployeeRole;
use App\Enums\InventoryPermission;
use App\Filament\Resources\ResponsibilityAssignments\ResponsibilityAssignmentResource;
use App\Filament\Tables\Columns\PurchaseCostHistoryColumn;
use App\Models\InventoryAllocationAccount;
use App\Models\InventoryAllocationEvent;
use App\Models\InventoryAllocationTransitionLine;
use App\Models\InventoryReservation;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Inventory\EmployeeOwnedInventoryReadService;
use App\Services\Inventory\InventoryAllocationBreakdownReadService;
use App\Services\Purchases\PurchaseCostHistoryService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class WorkspaceStockTable extends Component
{
    use WithPagination;

    #[Locked]
    public string $mode = 'locations';

    public string $search = '';

    public string $location = '';

    public string $holder = '';

    public string $stock = 'all';

    #[Locked]
    public array $applied = [];

    #[Locked]
    public ?int $detailsId = null;

    #[Locked]
    public bool $history = false;

    #[Locked]
    public string $detailTab = 'allocation';

    public function detailSection(string $section): void
    {
        abort_unless(in_array($section, ['allocation', 'info', 'provenance', 'reservations'], true), 404);
        $this->detailTab = $section;
    }

    private function query(bool $filters = true)
    {
        $user = auth()->user();
        $authorization = app(InventoryAuthorization::class);
        abort_unless($user && in_array($this->mode, ['mine', 'locations'], true)
            && $authorization->allows($user, InventoryPermission::View)
            && $authorization->allows($user, InventoryPermission::ViewLocationBalances), 403);
        $service = app(EmployeeOwnedInventoryReadService::class);
        $query = $service->locationInventories($user);
        if ($this->mode === 'mine') {
            // Personal stock is a subdivision, never an additional physical total.
            $query->whereIn('product_inventories.id', $service->inventoryIds($user));
        }
        if ($filters) {
            $f = $this->applied;
            if (filled($f['search'] ?? null)) {
                $needle = '%'.addcslashes(trim($f['search']), '%_\\').'%';
                $query->whereHas('product', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', $needle)->orWhere('sku', 'like', $needle)));
            }
            if (filled($f['location'] ?? null)) {
                $query->where('product_inventories.warehouse_id', $f['location']);
            }
            if (filled($f['holder'] ?? null)) {
                abort_unless(! $service->isEmployeeView($user), 403);
                $query->whereIn('product_inventories.id', app(InventoryAllocationBreakdownReadService::class)->inventoryIdsForHolder($user, (int) $f['holder']));
            }
            if (($f['stock'] ?? 'all') === 'in_stock') {
                if ($this->mode === 'mine') {
                    $query->whereIn('product_inventories.id', $service->balances($user)->whereColumn('allocated_quantity', '>', 'reserved_quantity')->select('product_inventory_id'));
                } else {
                    $query->whereColumn('product_inventories.available_quantity', '>', 'product_inventories.reserved_quantity');
                }
            }
            if (($f['stock'] ?? 'all') === 'zero_stock') {
                if ($this->mode === 'mine') {
                    $query->whereNotIn('product_inventories.id', $service->balances($user)->whereColumn('allocated_quantity', '>', 'reserved_quantity')->select('product_inventory_id'));
                } else {
                    $query->whereColumn('product_inventories.available_quantity', '<=', 'product_inventories.reserved_quantity');
                }
            }
        }

        return $query->with(['product:id,sku,name,status,brand,brand_id,category,condition,model', 'warehouse:id,name,code']);
    }

    public function applyFilters(): void
    {
        $this->validate(['search' => ['string', 'max:200'], 'location' => ['nullable', 'integer', 'min:1'], 'holder' => ['nullable', 'integer', 'min:1'], 'stock' => ['in:all,in_stock,zero_stock']]);
        $this->applied = $this->only(['search', 'location', 'holder', 'stock']);
        $this->query()->count();
        $this->resetPage('workspace-stock-page');
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'location', 'holder', 'stock', 'applied');
        $this->resetPage('workspace-stock-page');
    }

    public function details(int $inventoryId, string $section = 'allocation'): void
    {
        $this->query()->findOrFail($inventoryId);
        abort_unless(in_array($section, ['allocation', 'info', 'provenance', 'reservations'], true), 404);
        $this->detailsId = $inventoryId;
        $this->history = false;
        $this->detailTab = $section;
    }

    public function purchaseHistory(int $inventoryId): void
    {
        abort_unless(PurchaseCostHistoryColumn::allowed(), 403);
        $this->details($inventoryId);
        $this->history = true;
    }

    public function closeDetails(): void
    {
        $this->detailsId = null;
        $this->history = false;
    }

    #[On('workspace-stock-changed')]
    public function refreshStock(): void
    {
        $this->detailsId = null;
    }

    public function render()
    {
        $query = $this->query();
        $user = auth()->user();
        $personal = $this->mode === 'mine';
        $service = app(EmployeeOwnedInventoryReadService::class);
        $all = (clone $query)->get();
        $own = $service->metrics($user, $all->pluck('id'));
        $summary = ['rows' => $all->count(), 'skus' => $all->pluck('product_id')->unique()->count(), 'held' => 0, 'reserved' => 0, 'allocated' => 0, 'sellable' => 0];
        foreach ($all as $row) {
            $held = (int) ($personal ? $own->get($row->id)?->allocated_quantity : $row->available_quantity);
            $reserved = (int) ($personal ? $own->get($row->id)?->reserved_quantity : $row->reserved_quantity);
            $summary['held'] += $held;
            $summary['reserved'] += $reserved;
            $summary['allocated'] += $personal ? $held : (int) $row->allocated_quantity;
            $summary['sellable'] += $held - $reserved;
        }
        $rows = (clone $query)->orderBy('product_inventories.product_id')->orderBy('product_inventories.warehouse_id')->paginate(15, pageName: 'workspace-stock-page');
        $financial = app(InventoryAuthorization::class)->allows($user, InventoryPermission::ViewFinancials);
        $costHistory = PurchaseCostHistoryColumn::allowed();
        $latest = collect();
        if ($costHistory) {
            $latestQuery = $this->query()->whereIn('product_inventories.id', $rows->getCollection()->modelKeys());
            app(PurchaseCostHistoryService::class)->addLatestCostSelect($latestQuery, 'product_inventories.product_id', $user);
            $latest = $latestQuery->get()->keyBy('id');
        }
        $details = $this->detailsId ? $this->query()->findOrFail($this->detailsId) : null;
        $detailLocations = $details ? $this->query(false)->where('product_inventories.product_id', $details->product_id)->get() : collect();
        $inTransit = (int) InventoryAllocationTransitionLine::query()->where('context_type', 'transfer')->where('status', 'dispatched')
            ->whereIn('source_product_inventory_id', $all->modelKeys())
            ->when($personal || $service->isEmployeeView($user), fn ($q) => $q->whereHas('account', fn ($q) => $q->where('employee_id', $user->employee->id)))
            ->sum('remaining_quantity');
        $reservationRows = $details ? InventoryReservation::query()->where('product_inventory_id', $details->id)
            ->when($service->isEmployeeView($user), fn ($q) => $q->whereHas('allocationLines.account', fn ($q) => $q->where('employee_id', $user->employee->id)))
            ->with('orderItem.order')->latest('id')->limit(30)->get() : collect();
        $events = $details && app(InventoryAuthorization::class)->allows($user, InventoryPermission::ViewAllocations)
            ? InventoryAllocationEvent::query()->where('product_inventory_id', $details->id)
                ->when($service->isEmployeeView($user), fn ($q) => $q->where(fn ($q) => $q->whereHas('fromAccount', fn ($q) => $q->where('employee_id', $user->employee->id))->orWhereHas('toAccount', fn ($q) => $q->where('employee_id', $user->employee->id))))
                ->with(['fromAccount:id,name', 'toAccount:id,name'])->latest('id')->limit(30)->get() : collect();
        $receipts = collect();
        if ($details && app(InventoryAuthorization::class)->allows($user, InventoryPermission::ViewAllocations)) {
            $ownAccountIds = InventoryAllocationAccount::query()->where('employee_id', $user->employee->id)->pluck('id');
            $receiverIds = InventoryAllocationEvent::query()->where('product_inventory_id', $details->id)
                ->whereIn('from_account_id', $ownAccountIds)->whereNotNull('to_account_id')->pluck('to_account_id');
            $receipts = InventoryAllocationTransitionLine::query()->where('context_type', 'transfer')
                ->where('source_product_inventory_id', $details->id)
                ->when($service->isEmployeeView($user), fn ($q) => $q->where(fn ($q) => $q->whereIn('account_id', $ownAccountIds)->orWhereIn('account_id', $receiverIds)))
                ->with('destinationInventory.warehouse:id,name')->orderByDesc('id')->limit(30)->get();
        }

        return view('livewire.workspace-stock-table', [
            'rows' => $rows, 'summary' => $summary, 'own' => $own, 'personal' => $personal, 'financial' => $financial,
            'costHistory' => $costHistory, 'latest' => $latest, 'details' => $details,
            'detailLocations' => $detailLocations, 'inTransit' => $inTransit,
            'detailCost' => $financial && $details ? DB::table('product_inventories')->where('id', $details->id)->value('average_cost') : null,
            'brandAssignments' => $details && $user->employee?->role === EmployeeRole::Owner
                && ResponsibilityAssignmentResource::canViewAny()
                    ? ResponsibilityAssignmentResource::getEloquentQuery()->active()
                        ->where(fn ($q) => $q->whereHas('brandScope', fn ($q) => $q->where('product_brand_id', $details->product->brand_id))
                            ->orWhereHas('productScope', fn ($q) => $q->where('product_id', $details->product_id)))
                        ->with('employee')->get() : collect(),
            'averageCosts' => $financial ? DB::table('product_inventories')->whereIn('id', $rows->getCollection()->modelKeys())->pluck('average_cost', 'id') : collect(),
            'breakdown' => $details ? app(InventoryAllocationBreakdownReadService::class)->forInventory($user, $details) : null,
            'reservationRows' => $reservationRows, 'events' => $events,
            'provenanceReceipts' => $receipts,
            'historyData' => $details && $this->history && $costHistory ? app(PurchaseCostHistoryService::class)->summaryForProduct($details->product_id, $user) : null,
            'locations' => $this->query(false)->get()->pluck('warehouse')->unique('id')->pluck('name', 'id'),
            'holders' => ! $service->isEmployeeView($user) ? InventoryAllocationAccount::query()->where('type', 'employee')->where('status', true)->pluck('name', 'id') : collect(),
        ]);
    }
}
