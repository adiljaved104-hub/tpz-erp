<?php

namespace App\Filament\Pages\Inventory;

use App\Enums\ResponsibilityPermission;
use App\Models\User;
use App\Services\Authorization\ResponsibilityAuthorization;
use App\Services\Responsibilities\ResponsibilityReadService;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MyInventory extends Page
{
    public string $search = '';

    public string $brand = '';

    public string $category = '';

    public string $platform = '';

    public string $warehouse = '';

    public string $stockStatus = '';

    public string $allocation = '';

    public string $visibleBecause = '';

    protected string $view = 'filament.pages.inventory.my-inventory';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'My Inventory';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(ResponsibilityAuthorization::class)->allows($user, ResponsibilityPermission::ViewOwn);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function getViewData(): array
    {
        $service = app(ResponsibilityReadService::class);
        $allRows = $service->myInventory(auth()->user());
        $inventoryRows = $this->filterRows($allRows);

        return [
            'inventoryRows' => $inventoryRows,
            'hasAuthorizedInventory' => $allRows->isNotEmpty(),
            'responsibilities' => $service->myResponsibilities(auth()->user()),
            'summary' => [
                'products' => $allRows->pluck('product_id')->unique()->count(),
                'usable' => $allRows->sum('employee_usable'),
                'low' => $allRows->where('stock_status', 'low_stock')->count(),
                'out' => $allRows->where('stock_status', 'out_of_stock')->count(),
            ],
            'filterOptions' => $this->filterOptions($allRows),
        ];
    }

    public function applyStockStatus(string $status): void
    {
        $this->stockStatus = $this->stockStatus === $status ? '' : $status;
    }

    public function resetInventoryFilters(): void
    {
        $this->reset('search', 'brand', 'category', 'platform', 'warehouse', 'stockStatus', 'allocation', 'visibleBecause');
    }

    /** @param Collection<int, object> $rows */
    private function filterRows(Collection $rows): Collection
    {
        $search = Str::lower(trim($this->search));

        return $rows->filter(function (object $row) use ($search): bool {
            if ($search !== '' && ! Str::contains(Str::lower(implode(' ', [$row->sku, $row->name, $row->model ?? ''])), $search)) {
                return false;
            }
            if ($this->brand !== '' && $row->brand !== $this->brand) {
                return false;
            }
            if ($this->category !== '' && $row->category !== $this->category) {
                return false;
            }
            if ($this->platform !== '' && ! in_array($this->platform, $row->platforms, true)) {
                return false;
            }
            if ($this->warehouse !== '' && ($this->warehouse === '__none' ? $row->warehouse !== null : $row->warehouse !== $this->warehouse)) {
                return false;
            }
            if ($this->stockStatus !== '' && $row->stock_status !== $this->stockStatus) {
                return false;
            }
            if ($this->allocation === 'shared' && $row->is_quantity_limited) {
                return false;
            }
            if ($this->allocation === 'quantity' && ! $row->is_quantity_limited) {
                return false;
            }
            if ($this->allocation === 'exhausted' && (! $row->is_quantity_limited || $row->remaining_allocation > 0)) {
                return false;
            }
            if ($this->visibleBecause !== '' && ! in_array($this->visibleBecause, $row->visibility_reasons, true)) {
                return false;
            }

            return true;
        })->values();
    }

    /** @param Collection<int, object> $rows */
    private function filterOptions(Collection $rows): array
    {
        $values = static fn (Collection $values): array => $values->filter()->unique()->sort()->values()->all();

        return [
            'brands' => $values($rows->pluck('brand')),
            'categories' => $values($rows->pluck('category')),
            'platforms' => $values($rows->flatMap(fn (object $row): array => $row->platforms)),
            'warehouses' => $values($rows->pluck('warehouse')),
            'reasons' => $values($rows->flatMap(fn (object $row): array => $row->visibility_reasons)),
            'hasNoBalance' => $rows->contains(fn (object $row): bool => $row->warehouse === null),
        ];
    }
}
