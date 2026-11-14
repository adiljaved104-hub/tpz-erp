<?php

namespace App\Filament\Pages;

use App\Enums\InventoryPermission;
use App\Enums\ProductPermission;
use App\Filament\Pages\Inventory\InventoryOverview;
use App\Filament\Pages\Inventory\StockAdjustments;
use App\Filament\Resources\ProductInventories\ProductInventoryResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\StockRequests\StockRequestResource;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\ProductAuthorization;
use App\Services\Inventory\InventoryLocationOverviewService;
use App\Services\Inventory\InventoryReadService;
use App\Services\Mobile\StockStatus;
use App\Services\Navigation\NavigationPreferenceService;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\WithPagination;

class InventoryWorkspace extends Page
{
    use WithPagination;

    protected string $view = 'filament.pages.inventory-workspace';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory & Warehouse';

    protected static ?string $navigationLabel = 'Inventory Workspace';

    protected static ?string $slug = 'inventory-workspace';

    protected static ?int $navigationSort = 0;

    public string $search = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(NavigationPreferenceService::class)->workspaceModeEnabled($user)
            && (
                app(InventoryAuthorization::class)->allows($user, InventoryPermission::View)
                || app(InventoryAuthorization::class)->allows($user, InventoryPermission::ViewStockRequests)
                || app(InventoryAuthorization::class)->allows($user, InventoryPermission::ViewLocationBalances)
                || app(ProductAuthorization::class)->allows($user, ProductPermission::View)
            );
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $authorization = app(InventoryAuthorization::class);
        $canInventory = $authorization->allows($user, InventoryPermission::View);
        $canLocationBalances = $authorization->allows($user, InventoryPermission::ViewLocationBalances);
        $products = ($canInventory && $canLocationBalances)
            ? app(InventoryLocationOverviewService::class)->forUser($user, false)
            : collect();
        $inventoryQuery = $canInventory
            ? app(InventoryReadService::class)->inventories($user)
            : null;

        if ($inventoryQuery !== null && trim($this->search) !== '') {
            $needle = addcslashes(trim($this->search), '%_\\\\');
            $inventoryQuery->whereHas('product', function ($query) use ($needle): void {
                $query->where(function ($product) use ($needle): void {
                    $product->where('sku', 'like', '%'.$needle.'%')
                        ->orWhere('name', 'like', '%'.$needle.'%');
                });
            });
        }

        $inventory = $inventoryQuery?->latest('product_inventories.updated_at')
            ->paginate(15, pageName: 'inventory-page') ?? collect();

        // Movement history must remain inside BOTH the existing responsibility scope
        // and the independent inventory.view_movements Access Control grant.
        $canSeeMovements = $authorization->allows($user, InventoryPermission::ViewMovements);
        $recentMovements = $canSeeMovements
            ? app(InventoryReadService::class)->movements($user)->latest('occurred_at')->limit(6)->get()
            : collect();

        // The same configurable threshold used by ERP stock intelligence;
        // product data already comes from the responsibility-scoped overview.
        $lowStockThreshold = app(StockStatus::class)->low();
        $lowStockProducts = $products
            ->filter(fn (array $product): bool => (int) $product['sellable'] <= $lowStockThreshold)
            ->sortBy('sellable')
            ->take(6)
            ->values();

        return [
            'inventory' => $inventory,
            'recentMovements' => $recentMovements,
            'lowStockProducts' => $lowStockProducts,
            'lowStockThreshold' => $lowStockThreshold,
            'summary' => [
                'products' => $products->count(),
                'available' => $products->sum('available'),
                'reserved' => $products->sum('reserved'),
                'allocated' => $products->sum('total_owned'),
            ],
            'canSeeSummary' => $canInventory && $canLocationBalances,
            'links' => array_filter([
                'products' => ProductResource::canViewAny() ? ProductResource::getUrl() : null,
                'inventory' => InventoryOverview::canAccess() ? InventoryOverview::getUrl() : null,
                'myInventory' => ProductInventoryResource::canViewAny() ? ProductInventoryResource::getUrl() : null,
                'requests' => StockRequestResource::canViewAny() ? StockRequestResource::getUrl() : null,
                'transfers' => StockTransferResource::canViewAny() ? StockTransferResource::getUrl() : null,
                'adjustments' => StockAdjustments::canAccess() ? StockAdjustments::getUrl() : null,
                'movements' => $canSeeMovements && StockMovementResource::canViewAny() ? StockMovementResource::getUrl() : null,
            ]),
            'canViewProducts' => ProductResource::canViewAny(),
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage('inventory-page');
    }
}
