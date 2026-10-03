<?php

namespace App\Filament\Pages\Inventory;

use App\Enums\EmployeeRole;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockByHolderReportService;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class StockByHolder extends Page
{
    use WithPagination;

    protected string $view = 'filament.pages.inventory.stock-by-holder';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Stock by Holder';

    protected static ?string $title = 'Stock by Holder';

    protected static ?int $navigationSort = 8;

    #[Url]
    public string $holderId = '';

    #[Url]
    public string $search = '';

    #[Url]
    public string $brandId = '';

    #[Url]
    public string $categoryId = '';

    #[Url]
    public string $warehouseId = '';

    #[Url]
    public bool $unassignedOnly = false;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['holderId', 'search', 'brandId', 'categoryId', 'warehouseId', 'unassignedOnly'], true)) {
            $this->resetPage();
        }
    }

    public function selectHolder(string $holderId): void
    {
        abort_unless(static::canAccess(), 403);
        $this->holderId = $holderId;
        $this->unassignedOnly = false;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('holderId', 'search', 'brandId', 'categoryId', 'warehouseId', 'unassignedOnly');
        $this->resetPage();
    }

    public function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $report = app(StockByHolderReportService::class);

        $filters = [
            'holder_id' => $this->holderId,
            'unassigned' => $this->unassignedOnly,
            'search' => $this->search,
            'brand_id' => $this->brandId,
            'category_id' => $this->categoryId,
            'warehouse_id' => $this->warehouseId,
        ];
        $holderSummaries = $report->holderSummaries($filters);
        $totalHeld = (int) $holderSummaries->sum('total_held');
        $reserved = (int) $holderSummaries->sum('reserved_quantity');
        $unassigned = (int) $holderSummaries->where('is_system', true)->sum('total_held');

        /** @var LengthAwarePaginator $balances */
        $balances = $report->balances($filters)->orderBy('account_id')->orderBy('product_inventory_id')->paginate(25);

        return [
            'holderSummaries' => $holderSummaries,
            'balances' => $balances,
            'metrics' => [
                'total_held' => $totalHeld,
                'available' => $totalHeld - $reserved,
                'reserved' => $reserved,
                'models' => $report->distinctModelCount($filters),
                'unassigned' => $unassigned,
            ],
            'holderOptions' => $report->holders()->mapWithKeys(fn ($account): array => [$account->id => $this->holderLabel($account)]),
            'brandOptions' => ProductBrand::query()->orderBy('name')->pluck('name', 'id'),
            'categoryOptions' => ProductCategory::query()->orderBy('name')->pluck('name', 'id'),
            'warehouseOptions' => Warehouse::query()->orderBy('name')->pluck('name', 'id'),
        ];
    }

    public function holderLabel(mixed $holder): string
    {
        if (data_get($holder, 'is_system')) {
            return 'Unassigned / System';
        }
        $employeeName = data_get($holder, 'employee.name', data_get($holder, 'employee_name'));
        if ($employeeName !== null) {
            $employeeId = data_get($holder, 'employee.employee_id', data_get($holder, 'employee_id'));
            $active = data_get($holder, 'employee.status', data_get($holder, 'employee_status'));

            return trim(($employeeId ? $employeeId.' · ' : '').$employeeName).($active ? '' : ' (Inactive)');
        }
        $teamName = data_get($holder, 'team.name', data_get($holder, 'team_name'));
        if ($teamName !== null) {
            $teamStatus = data_get($holder, 'team.status', data_get($holder, 'team_status'));

            return 'Team · '.$teamName.($teamStatus ? '' : ' (Inactive)');
        }

        return data_get($holder, 'name', data_get($holder, 'account_name', 'Allocation account')).' (Account unavailable)';
    }
}
