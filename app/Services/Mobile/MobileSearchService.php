<?php

namespace App\Services\Mobile;

use App\Enums\ChatPermission;
use App\Enums\EmployeeRole;
use App\Enums\InventoryPermission;
use App\Enums\PurchasePermission;
use App\Enums\QcPermission;
use App\Enums\ResponsibilityPermission;
use App\Models\MarketplaceOperationIncident;
use App\Models\MarketplaceOrderEvent;
use App\Models\User;
use App\Services\Authorization\ChatAuthorization;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\Authorization\QcAuthorization;
use App\Services\Authorization\ResponsibilityAuthorization;
use App\Services\Chat\ChatQueryService;
use App\Services\Chat\ConversationPresenter;
use App\Services\Hr\HrScopeService;
use App\Services\Qc\QcInspectionService;
use App\Services\Search\SearchQuery;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Collection;

class MobileSearchService
{
    /** @return Collection<int, array{group: string, label: string, description: string, target: array{module: string, id: int}}> */
    public function search(User $user, string $term, int $limit): Collection
    {
        $modules = $this->modules($user, $term);
        if (app(MobileWorkspaceCapabilities::class)->isQcFocused($user)) {
            return $modules->concat($this->qcInspections($user, $term, $limit));
        }

        return $modules
            ->concat($this->inventory($user, $term, $limit))
            ->concat($this->marketplaceIncidents($user, $term, $limit))
            ->concat($this->marketplaceOrders($user, $term, $limit))
            ->concat($this->leaveRequests($user, $term, $limit))
            ->concat($this->conversations($user, $term, $limit))
            ->concat($this->qcInspections($user, $term, $limit));
    }

    private function modules(User $user, string $term): Collection
    {
        $manifest = app(MobileManifest::class)->forUser($user);
        $modules = collect($manifest['modules'])->flatMap(function (array $module) use ($user): array {
            $key = $module['key'];
            $items = [[
                'label' => $module['title'],
                'description' => $module['description'],
                'aliases' => str_replace('_', ' ', $key).' '.$module['code'],
                'module' => $this->targetModule($key),
            ]];

            if ($key === 'sales') {
                $items[] = ['label' => 'Orders & Web Sales', 'description' => 'Authorized sales orders', 'aliases' => 'orders web sales', 'module' => 'orders'];
            }
            if ($key === 'purchases') {
                if (app(PurchaseAuthorization::class)->allows($user, PurchasePermission::ViewReceipts)) {
                    $items[] = ['label' => 'Purchase Receipts / GRN', 'description' => 'Goods received and purchase receipts', 'aliases' => 'grn receiving receipts', 'module' => 'purchase-receipts'];
                }
            }
            if ($key === 'hr') {
                foreach (app(MobileHrSectionService::class)->sections($user) as $section) {
                    $items[] = [
                        'label' => $section['title'],
                        'description' => 'Authorized HR records',
                        'aliases' => str_replace('-', ' ', $section['key']),
                        'module' => 'hr/'.$section['key'],
                    ];
                }
            }
            if ($key === 'qc') {
                $capabilities = $module['capabilities'] ?? [];
                if ($capabilities['inspections'] ?? false) {
                    $items[] = ['label' => 'QC Inspections', 'description' => 'Pending, assigned and completed QC work', 'aliases' => 'quality control inspections', 'module' => 'qc/inspections'];
                }
                if ($capabilities['dispatch'] ?? false) {
                    $items[] = ['label' => 'QC Dispatch', 'description' => 'Authorized physical-unit dispatch', 'aliases' => 'quality control shipping dispatch', 'module' => 'qc/dispatch'];
                }
                if ($capabilities['marketplace_transfers'] ?? false) {
                    $items[] = ['label' => 'QC Marketplace Transfers', 'description' => 'Assigned marketplace transfer devices', 'aliases' => 'quality control transfers', 'module' => 'qc/transfers'];
                }
            }

            return $items;
        });
        $needle = mb_strtolower(trim($term));

        return $modules
            ->filter(fn (array $item): bool => str_contains(mb_strtolower($item['label'].' '.$item['description'].' '.$item['aliases']), $needle))
            ->unique('module')
            ->take(12)
            ->map(fn (array $item): array => $this->result(
                'Modules & Pages',
                $item['label'],
                $item['description'],
                $item['module'],
                0,
            ))
            ->values();
    }

    private function inventory(User $user, string $term, int $limit): Collection
    {
        if (! app(InventoryAuthorization::class)->allows($user, InventoryPermission::View)
            && ! app(ResponsibilityAuthorization::class)->allows($user, ResponsibilityPermission::ViewOwn)) {
            return collect();
        }

        $query = app(MobileInventoryService::class)->query($user)
            ->whereNotNull('pi.id')
            ->select(['pi.id', 'p.name', 'p.sku', 'b.name as brand', 'w.name as warehouse']);
        SearchQuery::match($query, ['p.name', 'p.sku', 'b.name', 'w.name'], $term);
        SearchQuery::rank($query, 'p.sku', $term);
        SearchQuery::rank($query, 'p.name', $term);

        return $query->limit($limit)->get()->map(fn ($row): array => $this->result(
            'Inventory',
            $row->sku.' · '.$row->name,
            collect([$row->brand, $row->warehouse])->filter()->implode(' · '),
            'inventory',
            (int) $row->id,
        ));
    }

    private function marketplaceIncidents(User $user, string $term, int $limit): Collection
    {
        $query = MarketplaceOperationIncident::query()->with(['product:id,sku,name', 'platform:id,name', 'account:id,name']);
        if (! in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)) {
            $query->whereHas('recipients', fn (EloquentBuilder $recipients): EloquentBuilder => $recipients->where('user_id', $user->id));
        }
        $this->matchMarketplaceIncident($query, $term);

        return $query->latest('id')->limit($limit)->get()
            ->filter(fn (MarketplaceOperationIncident $incident): bool => app(NotificationTarget::class)->resolve($user, [
                'target_type' => 'marketplace_operation_incident', 'target_id' => $incident->id,
            ]) !== null)
            ->map(fn (MarketplaceOperationIncident $incident): array => $this->result(
                'Marketplace Incidents',
                str($incident->incident_type)->headline().' · '.($incident->product?->sku ?? 'Product'),
                collect([$incident->product?->name, $incident->platform?->name, $incident->account?->name])->filter()->implode(' · '),
                'marketplace-operations/incidents',
                $incident->id,
            ));
    }

    private function marketplaceOrders(User $user, string $term, int $limit): Collection
    {
        $query = MarketplaceOrderEvent::query()->with(['account.platform:id,name', 'items:id,marketplace_order_event_id,external_sku,title']);
        if (! in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)) {
            $ids = $user->notifications()->get(['data'])->pluck('data')
                ->filter(fn (array $data): bool => ($data['target_type'] ?? null) === 'marketplace_order_event')
                ->pluck('target_id')->filter(fn ($id): bool => is_numeric($id))->map(fn ($id): int => (int) $id);
            $query->whereIn('id', $ids);
        }
        $query->where(function (EloquentBuilder $match) use ($term): void {
            $like = '%'.$term.'%';
            $match->where('external_order_id', 'like', $like)
                ->orWhereHas('account', fn (EloquentBuilder $account): EloquentBuilder => $account->where('name', 'like', $like)
                    ->orWhereHas('platform', fn (EloquentBuilder $platform): EloquentBuilder => $platform->where('name', 'like', $like)))
                ->orWhereHas('items', fn (EloquentBuilder $items): EloquentBuilder => $items->where('external_sku', 'like', $like)->orWhere('title', 'like', $like));
        });

        return $query->latest('id')->limit($limit)->get()
            ->filter(fn (MarketplaceOrderEvent $event): bool => app(NotificationTarget::class)->resolve($user, [
                'target_type' => 'marketplace_order_event', 'target_id' => $event->id,
            ]) !== null)
            ->map(fn (MarketplaceOrderEvent $event): array => $this->result(
                'Marketplace Orders',
                $event->external_order_id,
                collect([$event->account?->platform?->name, $event->account?->name])->filter()->implode(' · '),
                'marketplace-operations/orders',
                $event->id,
            ));
    }

    private function leaveRequests(User $user, string $term, int $limit): Collection
    {
        $like = '%'.$term.'%';
        $query = app(HrScopeService::class)->leaveQuery($user)
            ->with(['employee:id,name,employee_id', 'type:id,name'])
            ->where(function (EloquentBuilder $match) use ($like): void {
                $match->where('reference', 'like', $like)->orWhere('reason', 'like', $like)
                    ->orWhereHas('employee', fn (EloquentBuilder $employee): EloquentBuilder => $employee->where('name', 'like', $like)->orWhere('employee_id', 'like', $like))
                    ->orWhereHas('type', fn (EloquentBuilder $type): EloquentBuilder => $type->where('name', 'like', $like));
            });

        return $query->latest('id')->limit($limit)->get()->map(fn ($leave): array => $this->result(
            'Leave Requests',
            $leave->reference.' · '.($leave->employee?->name ?? 'Employee'),
            collect([$leave->type?->name, ucfirst((string) $leave->status), $leave->from_date?->format('Y-m-d')])->filter()->implode(' · '),
            'hr/leave-requests',
            $leave->id,
        ));
    }

    private function conversations(User $user, string $term, int $limit): Collection
    {
        if (! app(ChatAuthorization::class)->allows($user, ChatPermission::View)
            || ! app(ChatAuthorization::class)->allows($user, ChatPermission::Search)) {
            return collect();
        }

        return app(ChatQueryService::class)->inbox($user, $term)
            ->limit($limit)->get()
            ->filter(fn ($conversation): bool => app(ChatAuthorization::class)->canAccessConversation($user, $conversation))
            ->map(fn ($conversation): array => $this->result(
                'Chat',
                app(ConversationPresenter::class)->label($conversation, $user),
                app(ConversationPresenter::class)->typeLabel($conversation),
                'chat',
                $conversation->id,
            ));
    }

    private function qcInspections(User $user, string $term, int $limit): Collection
    {
        if (! app(QcAuthorization::class)->allows($user, QcPermission::View)) {
            return collect();
        }
        $like = '%'.$term.'%';
        $query = app(QcInspectionService::class)->visible($user)
            ->with(['device:id,reference,serial', 'warehouse:id,name'])
            ->where(function (EloquentBuilder $match) use ($like): void {
                $match->where('product_snapshot->sku', 'like', $like)
                    ->orWhere('product_snapshot->title', 'like', $like)
                    ->orWhere('order_snapshot->reference', 'like', $like)
                    ->orWhereHas('device', fn (EloquentBuilder $device): EloquentBuilder => $device->where('reference', 'like', $like)->orWhere('serial', 'like', $like));
            });

        return $query->latest('id')->limit($limit)->get()->map(fn ($inspection): array => $this->result(
            'QC Inspections',
            collect([$inspection->device?->reference, data_get($inspection->product_snapshot, 'sku')])->filter()->implode(' · '),
            collect([data_get($inspection->product_snapshot, 'title'), $inspection->device?->serial, $inspection->status->value])->filter()->implode(' · '),
            'qc/inspections',
            $inspection->id,
        ));
    }

    private function matchMarketplaceIncident(EloquentBuilder $query, string $term): void
    {
        $like = '%'.$term.'%';
        $query->where(function (EloquentBuilder $match) use ($like): void {
            $match->where('incident_type', 'like', $like)
                ->orWhereHas('product', fn (EloquentBuilder $product): EloquentBuilder => $product->where('sku', 'like', $like)->orWhere('name', 'like', $like))
                ->orWhereHas('platform', fn (EloquentBuilder $platform): EloquentBuilder => $platform->where('name', 'like', $like))
                ->orWhereHas('account', fn (EloquentBuilder $account): EloquentBuilder => $account->where('name', 'like', $like));
        });
    }

    private function targetModule(string $key): string
    {
        return match ($key) {
            'inventory_locations' => 'inventory-locations',
            'stock_transfers' => 'stock-transfers',
            'stock_requests' => 'stock-requests',
            'internal_repairs' => 'internal-repairs',
            'claims' => 'cases/claims',
            'complaints' => 'cases/complaints',
            default => $key,
        };
    }

    private function result(string $group, string $label, string $description, string $module, int $id): array
    {
        return compact('group', 'label', 'description') + ['target' => compact('module', 'id')];
    }
}
