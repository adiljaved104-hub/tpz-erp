<?php

namespace App\Services\Mobile;

use App\Enums;
use App\Models\User;
use App\Services\Authorization;

/** Explicit presentation registry. Controllers and domain services remain authoritative. */
class MobileManifest
{
    public function forUser(User $user): array
    {
        $definitions = [
            'qc' => ['qc', null],
            'sales' => ['orders', Enums\OrderStatus::class, 'order-form', Authorization\OrderAuthorization::class, Enums\OrderPermission::Create],
            'products' => ['products', Enums\ProductStatus::class],
            'purchases' => ['purchases', Enums\PurchaseStatus::class, 'purchase-form', Authorization\PurchaseAuthorization::class, Enums\PurchasePermission::Create],
            'suppliers' => ['suppliers', null],
            'inventory_locations' => ['inventory-locations', null],
            'stock_transfers' => ['stock-transfers', Enums\StockTransferStatus::class, 'stock-transfer-form', Authorization\StockTransferAuthorization::class, Enums\StockTransferPermission::Create],
            'stock_requests' => ['stock-requests', Enums\StockRequestStatus::class, 'stock-request-form', Authorization\InventoryAuthorization::class, Enums\InventoryPermission::CreateStockRequests],
            'reservations' => ['reservations', Enums\InventoryReservationStatus::class],
            'invoices' => ['invoices', null],
            'responsibilities' => ['responsibilities', null],
            'returns' => ['returns', Enums\CustomerReturnStatus::class, 'return-order-picker', Authorization\CustomerReturnAuthorization::class, Enums\CustomerReturnPermission::Create],
            'warranty' => ['warranty', Enums\WarrantyRepairStatus::class],
            'internal_repairs' => ['internal-repairs', Enums\WarrantyRepairStatus::class],
            'claims' => ['cases/claims', Enums\SafetClaimStatus::class],
            'complaints' => ['cases/complaints', Enums\ComplaintStatus::class],
            'tasks' => ['tasks', Enums\TaskStatus::class, 'task-form', Authorization\TaskAuthorization::class, Enums\TaskPermission::Create],
        ];
        $modules = [];
        foreach (app(MobileWorkspaceCapabilities::class)->modules($user) as $index => $module) {
            $key = $module['key'];
            $definition = $definitions[$key] ?? null;
            $generic = $definition !== null;
            $path = $definition[0] ?? str_replace('_', '-', $key);
            $enum = $definition[1] ?? null;
            $create = isset($definition[3]) && app($definition[3])->allows($user, $definition[4]);
            if ($key === 'sales') {
                $create = $create && app(Authorization\OrderAuthorization::class)->allows($user, Enums\OrderPermission::EditSellingPrice);
            }
            $filters = [];
            if (in_array($key, ['purchases', 'returns', 'warranty', 'internal_repairs', 'claims', 'complaints', 'tasks'], true)) {
                $filters[] = ['name' => 'filter', 'label' => 'Records', 'type' => 'select', 'options' => $this->options($key === 'tasks' ? ['open', 'overdue', 'due_soon'] : ['open'])];
            }
            if ($key === 'sales') {
                $filters[] = ['name' => 'sale_type', 'label' => 'Sale type', 'type' => 'select', 'options' => $this->options(['standard', 'web'])];
                $filters[] = ['name' => 'period', 'label' => 'Period', 'type' => 'select', 'options' => $this->options(['today', 'week', 'month'])];
                $filters[] = ['name' => 'from', 'label' => 'From date', 'type' => 'date'];
                $filters[] = ['name' => 'to', 'label' => 'To date', 'type' => 'date'];
            }
            if ($key === 'qc') {
                $modules[] = [...$module, 'group' => 'Quality Control', 'order' => ($index + 1) * 10, 'renderer' => 'native',
                    'api_path' => '/workspace/qc', 'create' => ['enabled' => false], 'features' => ['queue' => true, 'detail' => true],
                    'capabilities' => ['pending' => '/workspace/qc/pending', 'dispatch' => '/workspace/qc/dispatch', 'detail' => '/workspace/qc/orders/{order}',
                        'scan' => app(Authorization\QcAuthorization::class)->allows($user, Enums\QcPermission::ScanDispatch)
                            && app(Authorization\QcAuthorization::class)->allows($user, Enums\QcPermission::ViewOrderAssignments)
                            && app(Authorization\QcAuthorization::class)->allows($user, Enums\QcPermission::AssignOrderDevice),
                        'ship' => app(Authorization\QcAuthorization::class)->allows($user, Enums\QcPermission::ShipDispatch) && app(Authorization\OrderAuthorization::class)->allows($user, Enums\OrderPermission::Fulfill), 'bulk_ship_limit' => 50]];

                continue;
            }
            $modules[] = [...$module,
                'group' => $module['title'], 'order' => ($index + 1) * 10,
                'renderer' => $generic ? 'generic' : 'native',
                'api_path' => $key === 'chat' ? '/chat' : '/workspace/'.$path,
                'record_module' => $key === 'internal_repairs' ? 'warranty' : $path,
                'searchable' => $generic,
                'list' => ['pagination' => $generic, 'fields' => ['title', 'subtitle', 'meta', 'status']],
                'detail' => ['fields' => true, 'items' => true, 'history' => true],
                'create' => ['enabled' => $create, 'renderer' => 'native', 'screen' => $create ? $definition[2] : null],
                'edit' => ['source' => 'record.actions', 'record_authorization_required' => true],
                'filters' => $filters,
                'statuses' => $enum ? $this->options(array_map(fn ($status) => $status->value, $enum::cases())) : ($key === 'invoices' ? $this->options(['draft', 'issued', 'voided']) : []),
                'actions' => ['source' => 'record.actions'],
                'features' => ['list' => true, 'detail' => $generic, 'create' => $create,
                    'edit' => $generic, 'server_fields' => $generic, 'server_actions' => $generic],
            ];
        }

        return ['schema_version' => 1, 'minimum_runtime_version' => (int) config('mobile.minimum_runtime_version', 1), 'modules' => $modules];
    }

    private function options(array $values): array
    {
        return array_map(fn (string $value) => ['value' => $value, 'label' => ucfirst(str_replace('_', ' ', $value))], $values);
    }
}
