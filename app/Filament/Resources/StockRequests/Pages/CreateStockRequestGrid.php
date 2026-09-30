<?php

namespace App\Filament\Resources\StockRequests\Pages;

use App\DTOs\StockRequests\CreateStockRequestData;
use App\DTOs\StockRequests\StockRequestItemData;
use App\Enums\InventoryPermission;
use App\Enums\StockRequestPurpose;
use App\Filament\Resources\StockRequests\StockRequestResource;
use App\Models\InventoryAllocationBalance;
use App\Models\ProductInventory;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Inventory\StockRequestService;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\WithPagination;
use Throwable;

class CreateStockRequestGrid extends Page
{
    use WithPagination;

    protected static string $resource = StockRequestResource::class;

    protected string $view = 'filament.resources.stock-requests.pages.create-stock-request-grid';

    public string $search = '';

    public string $brand = '';

    public string $warehouse = '';

    public string $holder = '';

    public int $perPage = 20;

    /** @var array<int, int|string|null> */
    public array $quantities = [];

    /** @var array<int, bool> */
    public array $selected = [];

    public string $reason = '';

    public string $idempotencyKey = '';

    public function mount(): void
    {
        $this->authorizeAccess();
        $this->idempotencyKey = (string) Str::uuid();
    }

    public function hydrate(): void
    {
        $this->authorizeAccess();
    }

    public function getTitle(): string
    {
        return 'Request Stock';
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedBrand(): void
    {
        $this->resetPage();
    }

    public function updatedWarehouse(): void
    {
        $this->resetPage();
    }

    public function updatedHolder(): void
    {
        $this->resetPage();
    }

    public function updatedQuantities(mixed $value, string $key): void
    {
        $this->selected[(int) $key] = is_numeric($value) && (int) $value > 0;
        $this->resetErrorBag("quantities.{$key}");
    }

    public function rows(): LengthAwarePaginator
    {
        $this->authorizeAccess();
        $actor = auth()->user();
        $query = ProductInventory::query()->with([
            'product:id,sku,name,brand_id', 'product.brandRelation:id,name', 'warehouse:id,name',
            'allocationBalances.account.employee:id,employee_id,name', 'allocationBalances.account.team:id,name',
        ])->select(['id', 'product_id', 'warehouse_id', 'available_quantity', 'reserved_quantity', 'damaged_quantity']);
        if (app(ResponsibilityProductScopeService::class)->requiresScope($actor)) {
            $query->whereIn('id', app(ResponsibilityProductScopeService::class)->inventoryIds($actor));
        }
        $search = trim($this->search);
        if ($search !== '') {
            $query->where(function (Builder $scope) use ($search): void {
                $scope->whereHas('product', fn (Builder $product) => $product->where('sku', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhereHas('brandRelation', fn (Builder $brand) => $brand->where('name', 'like', "%{$search}%")))
                    ->orWhereHas('warehouse', fn (Builder $warehouse) => $warehouse->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('allocationBalances.account', fn (Builder $account) => $account->where('name', 'like', "%{$search}%"));
            });
        }
        if ($this->brand !== '') {
            $query->whereHas('product', fn (Builder $product) => $product->where('brand_id', (int) $this->brand));
        }
        if ($this->warehouse !== '') {
            $query->where('warehouse_id', (int) $this->warehouse);
        }
        if ($this->holder !== '') {
            $query->whereHas('allocationBalances.account', fn (Builder $account) => $account->whereKey((int) $this->holder));
        }

        $rows = $query->orderBy('product_id')->orderBy('warehouse_id')->paginate(min(50, max(10, $this->perPage)));
        $inventoryIds = $rows->getCollection()->pluck('id');
        $statuses = DB::table('stock_request_items as item')->join('stock_requests as request', 'request.id', '=', 'item.stock_request_id')
            ->where('request.requested_by_employee_id', $actor->employee->id)
            ->whereIn('item.product_inventory_id', $inventoryIds)
            ->orderByDesc('request.id')
            ->get(['item.product_inventory_id', 'request.id as request_id', 'request.reference', 'request.status'])
            ->unique('product_inventory_id')->keyBy('product_inventory_id');
        $ownEmployeeId = $actor->employee->id;
        $ownTeamId = $actor->employee->team_id;
        $rows->getCollection()->each(function (ProductInventory $row) use ($statuses, $ownEmployeeId, $ownTeamId): void {
            $balances = $row->allocationBalances->filter(fn (InventoryAllocationBalance $balance): bool => $balance->account?->status === true);
            $isOwn = fn (InventoryAllocationBalance $balance): bool => $balance->account->employee_id === $ownEmployeeId
                || ($ownTeamId !== null && $balance->account->team_id === $ownTeamId);
            $row->my_stock = (int) $balances->filter($isOwn)->sum(fn (InventoryAllocationBalance $balance): int => $balance->availableQuantity());
            $row->available_from_others = (int) $balances->reject($isOwn)
                ->reject(fn (InventoryAllocationBalance $balance): bool => $balance->account->is_system)
                ->sum(fn (InventoryAllocationBalance $balance): int => $balance->availableQuantity());
            $row->requestable = (int) $balances->sum(fn (InventoryAllocationBalance $balance): int => $balance->availableQuantity());
            $row->holder_labels = $balances->filter(fn (InventoryAllocationBalance $balance): bool => $balance->allocated_quantity > 0)
                ->map(fn (InventoryAllocationBalance $balance): string => $balance->account->name.' ('.$balance->availableQuantity().' available)')->values()->all();
            $row->request_status = $statuses->get($row->id);
        });

        return $rows;
    }

    /** @return array{brands: array<int, string>, warehouses: array<int, string>, holders: array<int, string>} */
    public function filterOptions(): array
    {
        $this->authorizeAccess();
        $query = DB::table('product_inventories as pi');
        if (app(ResponsibilityProductScopeService::class)->requiresScope(auth()->user())) {
            $query->whereIn('pi.id', app(ResponsibilityProductScopeService::class)->inventoryIds(auth()->user()));
        }
        $ids = $query->select('pi.id');

        return [
            'brands' => DB::table('product_brands as b')->join('products as p', 'p.brand_id', '=', 'b.id')
                ->join('product_inventories as pi', 'pi.product_id', '=', 'p.id')->whereIn('pi.id', $ids)->distinct()->orderBy('b.name')->pluck('b.name', 'b.id')->all(),
            'warehouses' => DB::table('warehouses as w')->join('product_inventories as pi', 'pi.warehouse_id', '=', 'w.id')
                ->whereIn('pi.id', $ids)->distinct()->orderBy('w.name')->pluck('w.name', 'w.id')->all(),
            'holders' => DB::table('inventory_allocation_accounts as account')->join('inventory_allocation_balances as balance', 'balance.account_id', '=', 'account.id')
                ->whereIn('balance.product_inventory_id', $ids)->where('account.status', true)->where('balance.allocated_quantity', '>', 0)
                ->distinct()->orderBy('account.name')->limit(100)->pluck('account.name', 'account.id')->all(),
        ];
    }

    public function submit(): void
    {
        $this->authorizeAccess();
        $this->validate([
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'quantities' => ['array'],
            'quantities.*' => ['nullable', 'integer', 'min:0'],
        ]);
        $items = collect($this->quantities)->filter(fn ($quantity, $inventoryId): bool => (int) $quantity > 0 && ($this->selected[(int) $inventoryId] ?? false))
            ->map(fn ($quantity, $inventoryId): StockRequestItemData => new StockRequestItemData((int) $inventoryId, (int) $quantity))
            ->values()->all();
        if ($items === [] || count($items) > 100) {
            throw ValidationException::withMessages(['quantities' => 'Enter a quantity for 1 to 100 stock rows.']);
        }

        try {
            $request = app(StockRequestService::class)->create(new CreateStockRequestData(
                StockRequestPurpose::PermanentTransfer, null, $items, $this->reason, $this->idempotencyKey,
            ), auth()->user());
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title('Stock Request was not submitted')->body(collect($exception->errors())->flatten()->first())->send();
            throw $exception;
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->danger()->title('Stock Request was not submitted')->body('Please review the selected rows and try again.')->send();

            return;
        }

        $this->quantities = [];
        $this->selected = [];
        $this->reason = '';
        $this->idempotencyKey = (string) Str::uuid();
        Notification::make()->success()->title("Stock Request {$request->reference} submitted")->send();
        $this->redirect(StockRequestResource::getUrl('view', ['record' => $request]), navigate: true);
    }

    private function authorizeAccess(): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && app(InventoryAuthorization::class)->allows($actor, InventoryPermission::CreateStockRequests), 403);
    }
}
