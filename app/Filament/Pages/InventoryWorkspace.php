<?php

namespace App\Filament\Pages;

use App\Enums\InventoryPermission;
use App\Filament\Pages\Inventory\InventoryOverview;
use App\Filament\Pages\Inventory\StockAdjustments;
use App\Filament\Resources\ProductInventories\ProductInventoryResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\StockRequests\StockRequestResource;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;
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
            && app(InventoryAuthorization::class)->allows($user, InventoryPermission::View);
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
        $inventory = app(InventoryReadService::class)->inventories($user)
            ->latest('product_inventories.updated_at')->limit(10)->get()
            ->filter(fn ($row): bool => $this->search === '' || str_contains(mb_strtolower($row->product?->sku.' '.$row->product?->name), mb_strtolower($this->search)));

        return [
            'inventory' => $inventory,
            'links' => [
                'products' => ProductResource::getUrl(),
                'inventory' => InventoryOverview::getUrl(),
                'myInventory' => ProductInventoryResource::getUrl(),
                'requests' => StockRequestResource::getUrl(),
                'transfers' => StockTransferResource::getUrl(),
                'adjustments' => StockAdjustments::getUrl(),
            ],
        ];
    }
}
