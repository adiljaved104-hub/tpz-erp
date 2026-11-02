<?php

namespace App\Filament\Pages\Service;

use App\Enums\WarrantyRepairPermission;
use App\Enums\WarrantyRepairStatus;
use App\Models\User;
use App\Services\Authorization\WarrantyRepairAuthorization;
use App\Services\ServiceCases\RepairManagementReadService;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class RepairManagement extends Page
{
    use WithPagination;

    protected string $view = 'filament.pages.service.repair-management';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedWrench;

    protected static string|\UnitEnum|null $navigationGroup = 'Returns & Service';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Repair Management';

    protected static ?string $title = 'Repair Management';

    #[Url]
    public string $tab = 'all';

    #[Url]
    public string $search = '';

    #[Url]
    public string $technician = '';

    #[Url]
    public string $status = '';

    #[Url]
    public ?int $platformId = null;

    #[Url]
    public ?int $assignedToUserId = null;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(WarrantyRepairAuthorization::class)->allows($user, WarrantyRepairPermission::View);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function setTab(string $tab): void
    {
        abort_unless(in_array($tab, array_keys($this->tabs()), true), 422);
        $this->tab = $tab;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'technician', 'status', 'platformId', 'assignedToUserId', 'from', 'to');
        $this->resetPage();
    }

    /** @return array<string, string> */
    public function tabs(): array
    {
        return [
            'all' => 'All Repairs', 'technician' => 'With Technician', 'parts' => 'Waiting for Parts',
            'qc' => 'Ready for QC', 'classification' => 'Pending Classification',
            'completed' => 'Completed', 'failed' => 'Failed / Cannot Repair',
        ];
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $user = auth()->user();
        $service = app(RepairManagementReadService::class);
        $options = $service->filterOptions($user);

        return [
            'rows' => $service->paginate($user, [
                'tab' => $this->tab, 'search' => $this->search, 'technician' => $this->technician,
                'status' => $this->status, 'platform_id' => $this->platformId,
                'assigned_to_user_id' => $this->assignedToUserId, 'from' => $this->from, 'to' => $this->to,
            ]),
            'summary' => $service->summary($user),
            'tabs' => $this->tabs(),
            'statuses' => collect(WarrantyRepairStatus::cases())->mapWithKeys(fn (WarrantyRepairStatus $status): array => [$status->value => $status->getLabel()]),
            'platforms' => $options['platforms'],
            'assignees' => $options['assignees'],
        ];
    }
}
