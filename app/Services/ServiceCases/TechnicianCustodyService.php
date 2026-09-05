<?php

namespace App\Services\ServiceCases;

use App\Enums\WarrantyRepairPermission;
use App\Enums\WarrantyRepairStatus;
use App\Filament\Resources\InternalRepairs\InternalRepairResource;
use App\Filament\Resources\WarrantyRepairs\WarrantyRepairResource;
use App\Models\User;
use App\Models\WarrantyRepair;
use App\Services\Authorization\WarrantyRepairAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class TechnicianCustodyService
{
    private const STATUSES = [
        'send_to_technician',
        'in_repair',
        'waiting_for_parts',
        'repair_completed',
    ];

    public function __construct(private readonly WarrantyRepairAuthorization $authorization) {}

    /** @param array<string, mixed> $filters */
    public function paginate(User $user, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $paginator = $this->query($user, $filters)
            ->orderByRaw('COALESCE(sent_event.sent_at, warranty_repairs.sent_to_technician_at, warranty_repairs.received_at) ASC')
            ->orderBy('warranty_repairs.reference')
            ->paginate($perPage);
        $paginator->setCollection($paginator->getCollection()->map(fn (object $row): array => $this->present($row)));

        return $paginator;
    }

    /** @param array<string, mixed> $filters
     * @return array{total:int,in_repair:int,waiting_for_parts:int,overdue:int,repair_completed:int}
     */
    public function summary(User $user, array $filters): array
    {
        $cutoff = now()->subDays(14);
        $row = DB::query()->fromSub($this->query($user, $filters), 'technician_custody')
            ->selectRaw('COALESCE(SUM(quantity), 0) AS total_units')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'in_repair' THEN quantity ELSE 0 END), 0) AS in_repair_units")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'waiting_for_parts' THEN quantity ELSE 0 END), 0) AS waiting_units")
            ->selectRaw('COALESCE(SUM(CASE WHEN received_at < ? THEN quantity ELSE 0 END), 0) AS overdue_units', [$cutoff])
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'repair_completed' THEN quantity ELSE 0 END), 0) AS completed_units")
            ->first();

        return [
            'total' => (int) $row->total_units,
            'in_repair' => (int) $row->in_repair_units,
            'waiting_for_parts' => (int) $row->waiting_units,
            'overdue' => (int) $row->overdue_units,
            'repair_completed' => (int) $row->completed_units,
        ];
    }

    /** @return array<string, array<int|string, string>> */
    public function filterOptions(User $user): array
    {
        $base = fn (): Builder => DB::query()->fromSub($this->query($user), 'custody_options');

        return [
            'technicians' => $base()->whereNotNull('service_provider')->where('service_provider', '!=', '')->distinct()->orderBy('service_provider')->pluck('service_provider', 'service_provider')->all(),
            'platforms' => $base()->whereNotNull('platform_id')->distinct()->orderBy('platform_name')->pluck('platform_name', 'platform_id')->all(),
            'products' => $base()->distinct()->orderBy('product_name')->pluck('product_name', 'product_id')->all(),
            'assignees' => $base()->whereNotNull('assigned_to_user_id')->distinct()->orderBy('assigned_to_name')->pluck('assigned_to_name', 'assigned_to_user_id')->all(),
        ];
    }

    /** @param array<string, mixed> $filters */
    public function query(User $user, array $filters = []): Builder
    {
        $this->authorization->authorize($user, WarrantyRepairPermission::View);
        $scopedIds = $this->authorization->scopeQuery(WarrantyRepair::query()->select('warranty_repairs.id'), $user);
        $sentEvents = DB::table('warranty_repair_status_events')
            ->where('to_status', WarrantyRepairStatus::SendToTechnician->value)
            ->select('warranty_repair_id')
            ->selectRaw('MIN(changed_at) AS sent_at')
            ->groupBy('warranty_repair_id');

        return DB::table('warranty_repairs')
            ->join('products as product', 'product.id', '=', 'warranty_repairs.product_id')
            ->leftJoin('marketplace_platforms as platform', 'platform.id', '=', 'warranty_repairs.marketplace_platform_id')
            ->leftJoin('orders as orders', 'orders.id', '=', 'warranty_repairs.order_id')
            ->leftJoin('customer_returns as customer_return', 'customer_return.id', '=', 'warranty_repairs.customer_return_id')
            ->leftJoin('users as assignee', 'assignee.id', '=', 'warranty_repairs.assigned_to_user_id')
            ->leftJoinSub($sentEvents, 'sent_event', 'sent_event.warranty_repair_id', '=', 'warranty_repairs.id')
            ->whereIn('warranty_repairs.id', $scopedIds)
            ->whereIn('warranty_repairs.status', self::STATUSES)
            ->when(filled($filters['technician'] ?? null), fn (Builder $query) => $query->where('warranty_repairs.service_provider', $filters['technician']))
            ->when(filled($filters['status'] ?? null), fn (Builder $query) => $query->where('warranty_repairs.status', $filters['status']))
            ->when(filled($filters['platform_id'] ?? null), fn (Builder $query) => $query->where('warranty_repairs.marketplace_platform_id', (int) $filters['platform_id']))
            ->when(filled($filters['product_id'] ?? null), fn (Builder $query) => $query->where('warranty_repairs.product_id', (int) $filters['product_id']))
            ->when(filled($filters['assigned_to_user_id'] ?? null), fn (Builder $query) => $query->where('warranty_repairs.assigned_to_user_id', (int) $filters['assigned_to_user_id']))
            ->select([
                'warranty_repairs.id', 'warranty_repairs.reference', 'warranty_repairs.quantity', 'warranty_repairs.service_provider',
                'warranty_repairs.sent_to_technician_at', 'warranty_repairs.expected_return_at', 'warranty_repairs.received_at',
                'warranty_repairs.status', 'warranty_repairs.source', 'warranty_repairs.damaged_stock_event_id', 'product.id as product_id', 'product.name as product_name',
                'product.sku', 'platform.id as platform_id', 'platform.name as platform_name', 'assignee.id as assigned_to_user_id',
                'assignee.name as assigned_to_name', 'orders.reference as order_reference', 'customer_return.reference as return_reference',
                'sent_event.sent_at as sent_event_at',
            ]);
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        $sentAt = filled($row->sent_event_at) ? CarbonImmutable::parse($row->sent_event_at) : (filled($row->sent_to_technician_at) ? CarbonImmutable::parse($row->sent_to_technician_at) : null);
        $receivedAt = CarbonImmutable::parse($row->received_at);
        $expectedReturn = filled($row->expected_return_at) ? CarbonImmutable::parse($row->expected_return_at) : null;

        return (array) $row + [
            'case_type' => $row->source === 'damaged_item' || $row->damaged_stock_event_id !== null ? 'Internal Repair' : 'Warranty / Customer Service',
            'case_url' => $row->source === 'damaged_item' || $row->damaged_stock_event_id !== null
                ? InternalRepairResource::getUrl('view', ['record' => $row->id])
                : WarrantyRepairResource::getUrl('view', ['record' => $row->id]),
            'status_label' => WarrantyRepairStatus::from($row->status)->getLabel(),
            'sent_date' => $sentAt?->timezone(config('app.timezone'))->format('d M Y, h:i A'),
            'days_with_technician' => $sentAt?->startOfDay()->diffInDays(now()->startOfDay()),
            'expected_return' => $expectedReturn?->timezone(config('app.timezone'))->format('d M Y, h:i A'),
            'sla_due' => $receivedAt->addDays(14)->timezone(config('app.timezone'))->format('d M Y, h:i A'),
            'context_reference' => $row->return_reference ?? $row->order_reference,
        ];
    }
}
