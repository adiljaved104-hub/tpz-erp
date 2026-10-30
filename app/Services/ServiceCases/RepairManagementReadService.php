<?php

namespace App\Services\ServiceCases;

use App\Enums\WarrantyRepairPermission;
use App\Enums\WarrantyRepairStatus;
use App\Models\MarketplacePlatform;
use App\Models\User;
use App\Models\WarrantyRepair;
use App\Services\Authorization\WarrantyRepairAuthorization;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class RepairManagementReadService
{
    public function __construct(private readonly WarrantyRepairAuthorization $authorization) {}

    /** @param array<string, mixed> $filters */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        return $this->query($user, $filters)
            ->with([
                'product:id,sku,name,condition', 'warehouse:id,name', 'platform:id,name', 'assignedTo:id,name',
                'customerReturn:id,reference', 'order:id,reference',
                'damagedStockEvent:id,reference,customer_return_id,order_id',
                'damagedStockEvent.customerReturn:id,reference', 'damagedStockEvent.order:id,reference',
                'stockClassification:id,warranty_repair_id,condition,destination_product_id,reference',
                'stockClassification.destinationProduct:id,sku', 'sentToTechnicianEvent',
            ])
            ->latest('warranty_repairs.updated_at')->orderByDesc('warranty_repairs.id')->paginate(25);
    }

    /** @return array<string, int> */
    public function summary(User $user): array
    {
        $base = $this->query($user, []);
        $technician = [WarrantyRepairStatus::SendToTechnician, WarrantyRepairStatus::InRepair,
            WarrantyRepairStatus::WaitingForParts, WarrantyRepairStatus::RepairCompleted];

        return [
            'active' => (clone $base)->whereNotIn('warranty_repairs.status', [WarrantyRepairStatus::Completed->value, WarrantyRepairStatus::Cancelled->value])->count(),
            'technician' => (clone $base)->whereIn('warranty_repairs.status', array_map(fn ($status) => $status->value, $technician))->count(),
            'parts' => (clone $base)->where('warranty_repairs.status', WarrantyRepairStatus::WaitingForParts->value)->count(),
            'qc' => (clone $base)->whereIn('warranty_repairs.status', [WarrantyRepairStatus::ReceivedBack->value, WarrantyRepairStatus::QcPending->value])->count(),
            'classification' => (clone $base)->where('warranty_repairs.status', WarrantyRepairStatus::PendingClassification->value)->count(),
            'overdue' => (clone $base)->whereNotIn('warranty_repairs.status', [WarrantyRepairStatus::Completed->value, WarrantyRepairStatus::Cancelled->value])
                ->whereNotNull('warranty_repairs.expected_return_at')->where('warranty_repairs.expected_return_at', '<', now())->count(),
        ];
    }

    /** @return array<string, array<int, string>> */
    public function filterOptions(User $user): array
    {
        $base = $this->query($user, []);
        $platformIds = (clone $base)->whereNotNull('warranty_repairs.marketplace_platform_id')
            ->distinct()->pluck('warranty_repairs.marketplace_platform_id');
        $assigneeIds = (clone $base)->whereNotNull('warranty_repairs.assigned_to_user_id')
            ->distinct()->pluck('warranty_repairs.assigned_to_user_id');

        return [
            'platforms' => MarketplacePlatform::query()->whereIn('id', $platformIds)->orderBy('name')->pluck('name', 'id')->all(),
            'assignees' => User::query()->whereIn('id', $assigneeIds)->orderBy('name')->pluck('name', 'id')->all(),
        ];
    }

    /** @param array<string, mixed> $filters */
    public function query(User $user, array $filters): Builder
    {
        $this->authorization->authorize($user, WarrantyRepairPermission::View);
        $query = $this->authorization->scopeQuery(WarrantyRepair::query(), $user);
        $tab = $filters['tab'] ?? 'all';
        $technicianStatuses = [WarrantyRepairStatus::SendToTechnician->value, WarrantyRepairStatus::InRepair->value,
            WarrantyRepairStatus::WaitingForParts->value, WarrantyRepairStatus::RepairCompleted->value];

        match ($tab) {
            'technician' => $query->whereIn('warranty_repairs.status', $technicianStatuses),
            'parts' => $query->where('warranty_repairs.status', WarrantyRepairStatus::WaitingForParts->value),
            'qc' => $query->whereIn('warranty_repairs.status', [WarrantyRepairStatus::ReceivedBack->value, WarrantyRepairStatus::QcPending->value]),
            'classification' => $query->where('warranty_repairs.status', WarrantyRepairStatus::PendingClassification->value),
            'completed' => $query->where('warranty_repairs.status', WarrantyRepairStatus::Completed->value),
            'failed' => $query->where(function (Builder $query): void {
                $query->where('warranty_repairs.status', WarrantyRepairStatus::CannotRepair->value)
                    ->orWhereHas('statusEvents', fn (Builder $events) => $events->where('to_status', WarrantyRepairStatus::Completed->value)
                        ->where('from_status', WarrantyRepairStatus::QcPending->value));
            }),
            default => null,
        };

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function (Builder $query) use ($like): void {
                $query->where('warranty_repairs.reference', 'like', $like)
                    ->orWhereHas('product', fn (Builder $product) => $product->where('sku', 'like', $like)
                        ->orWhere('name', 'like', $like)->orWhere('model', 'like', $like))
                    ->orWhereHas('damagedStockEvent', fn (Builder $damage) => $damage->where('reference', 'like', $like));
            });
        }
        if (filled($filters['technician'] ?? null)) {
            $query->where('warranty_repairs.service_provider', 'like', '%'.addcslashes(trim($filters['technician']), '%_\\').'%');
        }
        if (filled($filters['status'] ?? null)) {
            $query->where('warranty_repairs.status', $filters['status']);
        }
        if (filled($filters['platform_id'] ?? null)) {
            $query->where('warranty_repairs.marketplace_platform_id', (int) $filters['platform_id']);
        }
        if (filled($filters['assigned_to_user_id'] ?? null)) {
            $query->where('warranty_repairs.assigned_to_user_id', (int) $filters['assigned_to_user_id']);
        }
        if (filled($filters['from'] ?? null)) {
            $query->whereDate('warranty_repairs.received_at', '>=', $filters['from']);
        }
        if (filled($filters['to'] ?? null)) {
            $query->whereDate('warranty_repairs.received_at', '<=', $filters['to']);
        }

        return $query;
    }
}
