<?php

namespace App\Filament\Pages;

use App\Enums\InventoryPermission;
use App\Enums\ProductPermission;
use App\Filament\Pages\Inventory\InventoryOverview;
use App\Filament\Pages\Inventory\StockAdjustments;
use App\Filament\Resources\ProductInventories\ProductInventoryResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\StockRequests\StockRequestResource;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\ProductAuthorization;
use App\Services\Inventory\InventoryReadService;
use App\Services\Navigation\NavigationPreferenceService;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class InventoryWorkspace extends Page
{
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
        $inventory = $canInventory
            ? app(InventoryReadService::class)->inventories($user)
                ->latest('product_inventories.updated_at')
                ->limit(10)
                ->get()
                ->filter(fn ($row): bool => trim($this->search) === '' || str_contains(
                    mb_strtolower(($row->product?->sku ?? '').' '.($row->product?->name ?? '')),
                    mb_strtolower(trim($this->search)),
                ))
            : collect();

        return [
            'inventory' => $inventory,
            'links' => array_filter([
                'products' => ProductResource::canViewAny() ? ProductResource::getUrl() : null,
                'inventory' => InventoryOverview::canAccess() ? InventoryOverview::getUrl() : null,
                'myInventory' => ProductInventoryResource::canViewAny() ? ProductInventoryResource::getUrl() : null,
                'requests' => StockRequestResource::canViewAny() ? StockRequestResource::getUrl() : null,
                'transfers' => StockTransferResource::canViewAny() ? StockTransferResource::getUrl() : null,
                'adjustments' => StockAdjustments::canAccess() ? StockAdjustments::getUrl() : null,
            ]),
            'canViewProducts' => ProductResource::canViewAny(),
        ];
    }
}
