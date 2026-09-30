<?php

namespace App\Filament\Pages\Inventory;

use App\DTOs\Inventory\InventoryAdjustmentData;
use App\Enums\InventoryPermission;
use App\Enums\PurchasePermission;
use App\Filament\Pages\Purchasing\QuickStockPurchase;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAllocationAccount;
use App\Models\ProductInventory;
use App\Models\PurchaseReceiptItem;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\Inventory\InventoryAdjustmentService;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Inventory\InventoryReadService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\WithPagination;
use Throwable;

abstract class StockAdjustmentGridPage extends Page
{
    use WithPagination;

    protected string $view = 'filament.pages.inventory.stock-adjustments';

    public string $search = '';

    public string $brand = '';

    public string $warehouse = '';

    public string $holder = '';

    public string $stockFilter = '';

    public int $perPage = 20;

    public ?int $linkedInventoryId = null;

    public ?int $linkedReceiptItemId = null;

    /** @var array<int, array<string, mixed>> */
    public array $drafts = [];

    /** @var array<int, string> */
    public array $savedRows = [];

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(InventoryAuthorization::class)->allows($actor, InventoryPermission::View);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $receiptItemId = (int) request()->query('receipt_item_id', 0);
        if ($receiptItemId < 1) {
            return;
        }
        $receiptItem = PurchaseReceiptItem::query()->with('receipt.purchase')->findOrFail($receiptItemId);
        app(PurchaseAuthorization::class)->authorize(auth()->user(), PurchasePermission::ViewReceipts, $receiptItem->receipt->purchase);
        $inventory = ProductInventory::query()->where('product_id', $receiptItem->product_id)
            ->where('warehouse_id', $receiptItem->receipt->warehouse_id)->firstOrFail();
        app(InventoryAuthorization::class)->authorize(auth()->user(), InventoryPermission::AdjustStock, $inventory);
        $this->linkedInventoryId = $inventory->id;
        $this->linkedReceiptItemId = $receiptItem->id;
        $this->drafts[$inventory->id] = ['selected' => true, 'quantity' => 1, 'receipt_item_id' => $receiptItem->id, 'idempotency_key' => (string) Str::uuid()];
    }

    public function hydrate(): void
    {
        abort_unless(static::canAccess(), 403);
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

    public function updatedStockFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDrafts(mixed $value, string $key): void
    {
        $id = (int) explode('.', $key)[0];
        if ($id > 0 && $key !== "{$id}.selected") {
            $this->drafts[$id]['selected'] = true;
        }
        $this->savedRows[$id] = '';
        $this->resetErrorBag();
    }

    public function canAdjust(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(InventoryAuthorization::class)->allows($actor, InventoryPermission::AdjustStock);
    }

    public function canQuickPurchase(): bool
    {
        return QuickStockPurchase::canAccess();
    }

    public function rows(): LengthAwarePaginator
    {
        abort_unless(static::canAccess(), 403);
        $query = app(InventoryReadService::class)->inventories(auth()->user())->with([
            'product:id,sku,name,status,brand_id',
            'product.brandRelation:id,name', 'allocationBalances.account:id,name,status,is_system,employee_id,team_id',
        ]);
        $search = trim($this->search);
        if ($search !== '') {
            $query->where(function (Builder $scope) use ($search): void {
                $scope->whereHas('product', fn (Builder $product) => $product->where('sku', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%"))
                    ->orWhereHas('warehouse', fn (Builder $warehouse) => $warehouse->where('name', 'like', "%{$search}%"));
            });
        }
        if ($this->brand !== '') {
            $query->whereHas('product', fn (Builder $product) => $product->where('brand_id', (int) $this->brand));
        }
        if ($this->warehouse !== '') {
            $query->where('warehouse_id', (int) $this->warehouse);
        }
        if ($this->holder !== '') {
            $query->whereHas('allocationBalances', fn (Builder $balance) => $balance->where('account_id', (int) $this->holder)->where('allocated_quantity', '>', 0));
        }
        match ($this->stockFilter) {
            'saleable' => $query->where('available_quantity', '>', 0),
            'damaged' => $query->where('damaged_quantity', '>', 0),
            'reserved' => $query->where('reserved_quantity', '>', 0),
            default => null,
        };
        if ($this->linkedInventoryId !== null) {
            $query->orderByRaw('CASE WHEN product_inventories.id = ? THEN 0 ELSE 1 END', [$this->linkedInventoryId]);
        }
        $rows = $query->orderBy('product_id')->orderBy('warehouse_id')->paginate(min(50, max(10, $this->perPage)));
        $rows->getCollection()->each(function (ProductInventory $row): void {
            $row->holder_labels = $row->allocationBalances
                ->filter(fn ($balance): bool => $balance->allocated_quantity > 0 && $balance->account?->status)
                ->map(fn ($balance): string => $balance->account->name.' ('.$balance->allocated_quantity.')')->values()->all();
        });

        return $rows;
    }

    /** @return array{brands: array<int, string>, warehouses: array<int, string>, holders: array<int, string>} */
    public function filterOptions(): array
    {
        $ids = app(InventoryReadService::class)->inventories(auth()->user())->select('product_inventories.id');

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

    /** @return array<int, string> */
    public function increaseHolderOptions(ProductInventory $inventory): array
    {
        if (! $this->canAdjust()) {
            return [];
        }

        return InventoryAllocationAccount::query()->where('status', true)->orderBy('name')->get()
            ->filter(fn (InventoryAllocationAccount $account): bool => app(InventoryAllocationService::class)->canConsumeFromAccount(auth()->user(), $account))
            ->pluck('name', 'id')->all();
    }

    public function rowStatus(ProductInventory $inventory): string
    {
        if (($this->savedRows[$inventory->id] ?? '') !== '') {
            return $this->savedRows[$inventory->id];
        }
        $draft = $this->drafts[$inventory->id] ?? [];
        if (! ($draft['selected'] ?? false) || blank($draft['type'] ?? null) || (int) ($draft['quantity'] ?? 0) < 1) {
            return 'No Change';
        }
        if (! app(InventoryAuthorization::class)->allows(auth()->user(), InventoryPermission::AdjustStock, $inventory)) {
            return 'Permission Denied';
        }
        if ($draft['type'] === 'saleable_increase' && ($draft['is_new_purchase'] ?? null) === 'yes') {
            return 'New Purchase';
        }
        $quantity = (int) $draft['quantity'];
        if ($draft['type'] === 'restore_damaged' && $quantity > $inventory->damaged_quantity) {
            return 'Insufficient Damaged';
        }
        if (in_array($draft['type'], ['saleable_decrease', 'mark_damaged'], true)) {
            if ($quantity > $inventory->available_quantity) {
                return 'Insufficient Saleable';
            }
            if ($quantity > $inventory->sellableQuantity()) {
                return 'Reserved Stock Involved';
            }
        }
        if (mb_strlen(trim((string) ($draft['reason'] ?? ''))) < 5) {
            return 'Reason Required';
        }

        return 'Ready';
    }

    public function saveAll(): void
    {
        abort_unless($this->canAdjust(), 403);
        $entries = [];
        $purchaseRows = 0;
        foreach ($this->drafts as $inventoryId => $draft) {
            if (! ($draft['selected'] ?? false)) {
                continue;
            }
            if (blank($draft['type'] ?? null) && blank($draft['quantity'] ?? null)) {
                continue;
            }
            $inventory = ProductInventory::query()->findOrFail((int) $inventoryId);
            app(InventoryAuthorization::class)->authorize(auth()->user(), InventoryPermission::AdjustStock, $inventory);
            if (($draft['type'] ?? null) === 'saleable_increase' && ($draft['is_new_purchase'] ?? null) === 'yes') {
                abort_unless($this->canQuickPurchase(), 403);
                $purchaseRows++;

                continue;
            }
            $status = $this->rowStatus($inventory);
            if (! in_array($status, ['Ready', 'Reserved Stock Involved'], true)) {
                throw ValidationException::withMessages(["drafts.{$inventoryId}" => "This row is {$status}. Complete or correct it before saving."]);
            }
            if (($draft['type'] ?? null) === 'saleable_increase' && ! isset($draft['is_new_purchase'])) {
                throw ValidationException::withMessages(["drafts.{$inventoryId}.is_new_purchase" => 'Choose whether this is a new purchase.']);
            }
            $entries[] = ['inventory_id' => (int) $inventoryId, 'data' => new InventoryAdjustmentData(
                (int) $inventoryId, (string) $draft['type'], (int) $draft['quantity'], (string) $draft['reason'],
                (string) ($draft['idempotency_key'] ?? Str::uuid()),
                filled($draft['receipt_item_id'] ?? null) ? (int) $draft['receipt_item_id'] : null,
                filled($draft['allocation_account_id'] ?? null) ? (int) $draft['allocation_account_id'] : null,
                $draft['type'] === 'saleable_increase' ? false : null,
                filled($draft['valuation_unit_cost'] ?? null) ? (string) $draft['valuation_unit_cost'] : null,
            )];
        }
        if ($entries === []) {
            if ($purchaseRows > 0) {
                Notification::make()->info()->title('New Purchase rows were not adjusted')->body('Open Quick Stock Purchase from each marked row.')->send();

                return;
            }
            throw ValidationException::withMessages(['drafts' => 'Select a row, adjustment type, and quantity before saving.']);
        }

        try {
            $posted = app(InventoryAdjustmentService::class)->postBatch(array_column($entries, 'data'), auth()->user());
        } catch (ValidationException $exception) {
            $messages = [];
            foreach ($exception->errors() as $key => $errors) {
                if (preg_match('/^lines\.(\d+)(\..+)?$/', $key, $match)) {
                    $inventoryId = $entries[(int) $match[1]]['inventory_id'];
                    $messages['drafts.'.$inventoryId.($match[2] ?? '')] = $errors;
                    $this->savedRows[$inventoryId] = 'Error';
                } else {
                    $messages[$key] = $errors;
                }
            }
            Notification::make()->danger()->title('Stock adjustments were not posted')->body(collect($messages)->flatten()->first())->send();
            throw ValidationException::withMessages($messages);
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->danger()->title('Stock adjustments were not posted')->body('The batch failed. No stock changes were saved.')->send();
            throw $exception;
        }

        foreach ($entries as $entry) {
            unset($this->drafts[$entry['inventory_id']]);
            $this->savedRows[$entry['inventory_id']] = 'Saved';
        }
        Notification::make()->success()->title(count($posted).' stock adjustment(s) posted')
            ->body(collect($posted)->pluck('reference')->implode(', '))->send();
        if ($purchaseRows > 0) {
            Notification::make()->info()->title("{$purchaseRows} New Purchase row(s) kept")->body('Open Quick Stock Purchase from those rows.')->send();
        }
    }

    public function recentHistory(): Collection
    {
        $ids = app(InventoryReadService::class)->inventories(auth()->user())->select('product_inventories.id');

        return InventoryAdjustment::query()->with(['product:id,sku,name', 'warehouse:id,name', 'performedBy:id,name'])
            ->whereIn('product_inventory_id', $ids)->orderByDesc('performed_at')->orderByDesc('id')->limit(25)->get();
    }
}
