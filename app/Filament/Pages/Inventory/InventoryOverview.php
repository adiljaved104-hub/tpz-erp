<?php

namespace App\Filament\Pages\Inventory;

use App\Enums\InventoryLocationPermission;
use App\Models\ProductInventory;
use App\Models\User;
use App\Services\Authorization\InventoryLocationAuthorization;
use App\Services\Inventory\InventoryLocationOverviewService;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class InventoryOverview extends Page
{
    protected string $view = 'filament.pages.inventory.inventory-overview';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Inventory Overview';

    protected static ?string $title = 'Inventory Overview by Location';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(InventoryLocationAuthorization::class)->allows($user, InventoryLocationPermission::View);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();

        $service = app(InventoryLocationOverviewService::class);
        $products = $service->forUser($user);

        return [
            'products' => $products,
            'summary' => $service->summaryForProducts($products),
            'hasCompanyInventory' => ProductInventory::query()->exists(),
        ];
    }
}
