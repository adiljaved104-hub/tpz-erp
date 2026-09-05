<?php

namespace App\Filament\Pages\Inventory;

use App\Enums\CustomerReturnPermission;
use App\Enums\CustomerReturnReason;
use App\Filament\Resources\CustomerReturns\CustomerReturnResource;
use App\Filament\Resources\MarketplaceReturnRemovals\MarketplaceReturnRemovalResource;
use App\Models\User;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Returns\QcPendingQueueService;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class QcPending extends Page
{
    use WithPagination;

    protected string $view = 'filament.pages.inventory.qc-pending';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlassCircle;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'QC Pending';

    protected static ?string $title = 'QC Pending';

    #[Url]
    public string $search = '';

    #[Url]
    public ?int $warehouseId = null;

    #[Url]
    public ?int $platformId = null;

    #[Url]
    public string $returnReason = '';

    #[Url]
    public string $receivedFrom = '';

    #[Url]
    public ?int $minDaysPending = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(CustomerReturnAuthorization::class)->allows($user, CustomerReturnPermission::View);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'warehouseId', 'platformId', 'returnReason', 'receivedFrom', 'minDaysPending');
        $this->resetPage();
    }

    public function canInspect(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(CustomerReturnAuthorization::class)->allows($user, CustomerReturnPermission::Inspect);
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        /** @var User $user */
        $user = auth()->user();
        $service = app(QcPendingQueueService::class);
        $filters = $this->filters();
        $options = $service->filterOptions($user);

        return [
            'rows' => $service->paginate($user, $filters),
            'summary' => $service->summary($user, $filters),
            'canInspect' => $this->canInspect(),
            'warehouses' => $options['warehouses'],
            'platforms' => $options['platforms'],
            'reasons' => collect(CustomerReturnReason::cases())->mapWithKeys(fn (CustomerReturnReason $reason): array => [$reason->value => $reason->label()]),
        ];
    }

    public function inspectUrl(string $sourceKind, int $sourceId): string
    {
        return $sourceKind === 'marketplace_removal'
            ? MarketplaceReturnRemovalResource::getUrl('view', ['record' => $sourceId])
            : CustomerReturnResource::getUrl('view', ['record' => $sourceId]);
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        return ['search' => $this->search, 'warehouse_id' => $this->warehouseId, 'platform_id' => $this->platformId,
            'return_reason' => $this->returnReason ?: null, 'received_from' => $this->receivedFrom ?: null,
            'min_days_pending' => $this->minDaysPending];
    }
}
