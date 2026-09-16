<?php

namespace App\Filament\Pages\Inventory;

use App\Enums\InventoryLocationPermission;
use App\Models\ProductInventory;
use App\Models\User;
use App\Services\Authorization\InventoryLocationAuthorization;
use App\Services\Inventory\InventoryLocationOverviewService;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\WithPagination;

class InventoryOverview extends Page
{
    use WithPagination;

    public string $search = '';

    public string $warehouse = '';

    public string $platform = '';

    public string $stockStatus = '';

    public int $perPage = 10;

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
        $allProducts = $service->forUser($user);
        $filteredProducts = $this->filterProducts($allProducts);

        return [
            'products' => $this->paginateProducts($filteredProducts),
            'summary' => $service->summaryForProducts($allProducts),
            'filterOptions' => $this->filterOptions($allProducts),
            'hasAuthorizedInventory' => $allProducts->isNotEmpty(),
            'hasCompanyInventory' => ProductInventory::query()->exists(),
        ];
    }

    public function resetInventoryFilters(): void
    {
        $this->reset('search', 'warehouse', 'platform', 'stockStatus');
        $this->resetPage();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'warehouse', 'platform', 'stockStatus', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    /** @param Collection<int, array<string, mixed>> $products */
    private function filterProducts(Collection $products): Collection
    {
        $search = Str::lower(trim($this->search));

        return $products->filter(function (array $row) use ($search): bool {
            if ($search !== '' && ! Str::contains(Str::lower("{$row['product']->sku} {$row['product']->name}"), $search)) {
                return false;
            }
            if ($this->warehouse !== '' && ! $row['locations']->contains(fn (array $location): bool => $location['code'] === $this->warehouse)) {
                return false;
            }
            if ($this->platform !== '' && ! $row['locations']->contains(fn (array $location): bool => $location['platform'] === $this->platform)) {
                return false;
            }
            if ($this->stockStatus === 'out_of_stock' && $row['sellable'] !== 0) {
                return false;
            }
            if ($this->stockStatus === 'low_stock' && $row['sellable'] !== 1) {
                return false;
            }
            if ($this->stockStatus === 'in_stock' && $row['sellable'] <= 1) {
                return false;
            }

            return true;
        })->values();
    }

    /** @param Collection<int, array<string, mixed>> $products */
    private function filterOptions(Collection $products): array
    {
        $locations = $products->flatMap(fn (array $row): Collection => $row['locations']);

        return [
            'warehouses' => $locations->unique('code')->sortBy('name')->mapWithKeys(fn (array $location): array => [$location['code'] => "{$location['name']} ({$location['code']})"])->all(),
            'platforms' => $locations->pluck('platform')->filter()->unique()->sort()->values()->all(),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $products */
    private function paginateProducts(Collection $products): LengthAwarePaginator
    {
        $perPage = in_array($this->perPage, [10, 25, 50], true) ? $this->perPage : 10;
        $page = $this->getPage();

        return new LengthAwarePaginator(
            $products->forPage($page, $perPage)->values(),
            $products->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'pageName' => 'page'],
        );
    }
}
