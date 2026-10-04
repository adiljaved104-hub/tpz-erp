<?php

namespace App\Services\Mobile;

use App\Models\User;

class MobileWorkspaceManifest
{
    public const SCHEMA_VERSION = 1;

    public const MIN_RUNTIME_VERSION = 1;

    public function for(User $user): array
    {
        $modules = collect(app(MobileWorkspaceCapabilities::class)->modules($user))
            ->map(function (array $module): array {
                $definition = $this->definition((string) $module['key']);

                return [
                    ...$module,
                    ...$definition,
                ];
            })
            ->values()
            ->all();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'minimum_runtime_version' => self::MIN_RUNTIME_VERSION,
            'modules' => $modules,
        ];
    }

    private function definition(string $key): array
    {
        $definitions = [
            'sales' => $this->workspace('/workspace/orders', 'orders', true, true),
            'inventory' => $this->workspace('/workspace/inventory', 'products', false, false),
            'products' => $this->workspace('/workspace/products', 'products', true, true),
            'purchases' => $this->workspace('/workspace/purchases', 'purchases', true, true),
            'suppliers' => $this->workspace('/workspace/suppliers', 'suppliers', true, false),
            'inventory_locations' => $this->workspace('/workspace/inventory-locations', 'inventory-locations', true, false),
            'stock_transfers' => $this->workspace('/workspace/stock-transfers', 'stock-transfers', true, true),
            'stock_requests' => $this->workspace('/workspace/stock-requests', 'stock-requests', true, true),
            'reservations' => $this->workspace('/workspace/reservations', 'reservations', true, true),
            'invoices' => $this->workspace('/workspace/invoices', 'invoices', true, false),
            'reports' => $this->native('/workspace/reports'),
            'hr' => $this->native('/workspace/hr'),
            'responsibilities' => $this->workspace('/workspace/responsibilities', 'responsibilities', true, true),
            'returns' => $this->workspace('/workspace/returns', 'returns', true, true),
            'warranty' => $this->workspace('/workspace/warranty', 'warranty', true, true),
            'internal_repairs' => $this->workspace('/workspace/internal-repairs', 'internal-repairs', true, true),
            'claims' => $this->workspace('/workspace/cases/claims', 'cases/claims', true, true),
            'complaints' => $this->workspace('/workspace/cases/complaints', 'cases/complaints', true, true),
            'tasks' => $this->workspace('/workspace/tasks', 'tasks', true, true),
            'notifications' => $this->workspace('/workspace/notifications', 'notifications', false, true),
            'chat' => $this->native('/chat'),
        ];

        return $definitions[$key] ?? [
            'renderer' => 'workspace',
            'api_path' => '/workspace/'.str_replace('_', '-', $key),
            'record_module' => str_replace('_', '-', $key),
            'features' => [
                'list' => true,
                'detail' => true,
                'server_fields' => true,
                'server_actions' => true,
            ],
        ];
    }

    private function workspace(
        string $apiPath,
        string $recordModule,
        bool $detail,
        bool $serverActions,
    ): array {
        return [
            'renderer' => 'workspace',
            'api_path' => $apiPath,
            'record_module' => $recordModule,
            'features' => [
                'list' => true,
                'detail' => $detail,
                'server_fields' => $detail,
                'server_actions' => $serverActions,
            ],
        ];
    }

    private function native(string $apiPath): array
    {
        return [
            'renderer' => 'native',
            'api_path' => $apiPath,
            'record_module' => null,
            'features' => [
                'list' => true,
                'detail' => false,
                'server_fields' => false,
                'server_actions' => false,
            ],
        ];
    }
}
