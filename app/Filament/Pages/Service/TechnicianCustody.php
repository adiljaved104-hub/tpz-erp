<?php

namespace App\Filament\Pages\Service;

use App\Enums\WarrantyRepairPermission;
use App\Enums\WarrantyRepairStatus;
use App\Models\User;
use App\Services\Authorization\WarrantyRepairAuthorization;
use App\Services\ServiceCases\TechnicianCustodyService;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class TechnicianCustody extends Page
{
    use WithPagination;

    protected string $view = 'filament.pages.service.technician-custody';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedWrench;

    protected static string|\UnitEnum|null $navigationGroup = 'Service';

    protected static ?string $navigationLabel = 'Technician Custody';

    protected static ?string $title = 'Technician Custody Overview';

    #[Url]
    public string $technician = '';

    #[Url]
    public string $status = '';

    #[Url]
    public ?int $platformId = null;

    #[Url]
    public ?int $productId = null;

    #[Url]
    public ?int $assignedToUserId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(WarrantyRepairAuthorization::class)->allows($user, WarrantyRepairPermission::View);
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
        $this->reset('technician', 'status', 'platformId', 'productId', 'assignedToUserId');
        $this->resetPage();
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        /** @var User $user */
        $user = auth()->user();
        $service = app(TechnicianCustodyService::class);
        $filters = $this->filters();
        $options = $service->filterOptions($user);

        return [
            'rows' => $service->paginate($user, $filters),
            'summary' => $service->summary($user, $filters),
            'technicians' => $options['technicians'],
            'platforms' => $options['platforms'],
            'products' => $options['products'],
            'assignees' => $options['assignees'],
            'statuses' => collect([WarrantyRepairStatus::SendToTechnician, WarrantyRepairStatus::InRepair, WarrantyRepairStatus::WaitingForParts, WarrantyRepairStatus::RepairCompleted])->mapWithKeys(fn (WarrantyRepairStatus $status): array => [$status->value => $status->getLabel()]),
        ];
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        return [
            'technician' => $this->technician ?: null,
            'status' => $this->status ?: null,
            'platform_id' => $this->platformId,
            'product_id' => $this->productId,
            'assigned_to_user_id' => $this->assignedToUserId,
        ];
    }
}
