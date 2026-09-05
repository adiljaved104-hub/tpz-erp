<?php

namespace App\Services\Dashboard;

use App\Enums\ChatPermission;
use App\Enums\ComplaintPermission;
use App\Enums\CustomerReturnPermission;
use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Enums\InventoryPermission;
use App\Enums\OrderPermission;
use App\Enums\ResponsibilityPermission;
use App\Enums\SafetClaimPermission;
use App\Enums\TaskPermission;
use App\Enums\WarrantyRepairPermission;
use App\Models\User;
use App\Services\Authorization\ChatAuthorization;
use App\Services\Authorization\ComplaintAuthorization;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Authorization\HrAuthorization;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\ResponsibilityAuthorization;
use App\Services\Authorization\SafetClaimAuthorization;
use App\Services\Authorization\TaskAuthorization;
use App\Services\Authorization\WarrantyRepairAuthorization;
use Illuminate\Support\Collection;

class DashboardWidgetRegistry
{
    public const DASHBOARD_KEY = 'erp';

    public function __construct(
        private readonly OrderAuthorization $orders,
        private readonly InventoryAuthorization $inventory,
        private readonly ResponsibilityAuthorization $responsibilities,
        private readonly CustomerReturnAuthorization $returns,
        private readonly SafetClaimAuthorization $claims,
        private readonly WarrantyRepairAuthorization $warranty,
        private readonly ComplaintAuthorization $complaints,
        private readonly TaskAuthorization $tasks,
        private readonly ChatAuthorization $chat,
        private readonly HrAuthorization $hr,
    ) {}

    /** @return Collection<string, array<string, mixed>> */
    public function authorized(User $user): Collection
    {
        return $this->definitions()
            ->filter(fn (array $definition): bool => $this->isAuthorized($user, $definition['key']))
            ->sortBy(fn (array $definition): int => $definition['default_role_positions'][$user->employee->role->value] ?? PHP_INT_MAX);
    }

    /** @return Collection<string, array<string, mixed>> */
    public function definitions(): Collection
    {
        $definitions = collect([
            $this->definition('sales', 'Sales & Orders', 'dashboard.sales', 'Sales', ['orders.view'], 'heroicon-o-shopping-bag'),
            $this->definition('inventory', 'Inventory', 'dashboard.inventory', 'Inventory', ['inventory.view or responsibility.view_own'], 'heroicon-o-cube'),
            $this->definition('inventory_intelligence', 'Inventory Intelligence', 'dashboard.inventory-intelligence', 'Inventory', ['inventory.view or responsibility.view_own'], 'heroicon-o-chart-bar-square'),
            $this->definition('service', 'Returns, Claims & Service', 'dashboard.service', 'Returns / Claims / Warranty', ['authorized source-module view access'], 'heroicon-o-wrench-screwdriver'),
            $this->definition('work', 'Tasks & Communication', 'dashboard.work', 'Tasks', ['task/chat/notification access'], 'heroicon-o-clipboard-document-check'),
            $this->definition('hr', 'HR & Attendance', 'dashboard.hr', 'HR', ['existing HR view permissions'], 'heroicon-o-user-group'),
            $this->definition('attention', 'Needs Attention', 'dashboard.attention', 'Alerts', ['derived from authorized source modules'], 'heroicon-o-exclamation-triangle'),
            $this->definition('responsibilities', 'My Responsibilities', 'dashboard.responsibilities', 'Operations', ['responsibility.view_own'], 'heroicon-o-user-group'),
        ])->keyBy('key');

        $positions = $this->roleLayouts();

        return $definitions->map(function (array $definition) use ($positions): array {
            $definition['default_role_positions'] = collect($positions)
                ->mapWithKeys(fn (array $keys, string $role): array => [$role => (int) array_search($definition['key'], $keys, true)])
                ->all();

            return $definition;
        });
    }

    /** @return array<int, string> */
    public function defaultLayout(User $user): array
    {
        return $this->authorized($user)->keys()->values()->all();
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return $this->definitions()->keys()->all();
    }

    public function isAuthorized(User $user, string $key): bool
    {
        return match ($key) {
            'sales' => $this->orders->allows($user, OrderPermission::View),
            'inventory', 'inventory_intelligence' => $this->inventory->allows($user, InventoryPermission::View)
                || $this->responsibilities->allows($user, ResponsibilityPermission::ViewOwn),
            'service' => $this->returns->allows($user, CustomerReturnPermission::View)
                || $this->claims->allows($user, SafetClaimPermission::View)
                || $this->warranty->allows($user, WarrantyRepairPermission::View)
                || $this->complaints->allows($user, ComplaintPermission::View),
            'work' => $this->tasks->allows($user, TaskPermission::View)
                || $this->chat->allows($user, ChatPermission::View)
                || $user->employee?->status === true,
            'hr' => $this->hasHrAccess($user),
            'attention' => $user->employee?->status === true,
            'responsibilities' => $this->responsibilities->allows($user, ResponsibilityPermission::ViewOwn),
            default => false,
        };
    }

    /** @return array<string, mixed> */
    private function definition(string $key, string $label, string $component, string $category, array $permissions, string $icon): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'component' => $component,
            'category' => $category,
            'permission_requirements' => $permissions,
            'default_visible' => true,
            'default_role_positions' => [],
            'span' => 'full',
            'icon' => $icon,
        ];
    }

    /** @return array<string, array<int, string>> */
    private function roleLayouts(): array
    {
        return [
            EmployeeRole::Owner->value => ['sales', 'inventory', 'inventory_intelligence', 'work', 'attention', 'service', 'hr', 'responsibilities'],
            EmployeeRole::Admin->value => ['sales', 'work', 'inventory', 'attention', 'service', 'inventory_intelligence', 'hr', 'responsibilities'],
            EmployeeRole::Manager->value => ['work', 'attention', 'sales', 'inventory', 'service', 'hr', 'inventory_intelligence', 'responsibilities'],
            EmployeeRole::Staff->value => ['work', 'attention', 'sales', 'inventory', 'responsibilities', 'service', 'hr', 'inventory_intelligence'],
        ];
    }

    private function hasHrAccess(User $user): bool
    {
        foreach ([
            HrPermission::AttendanceViewOwn,
            HrPermission::AttendanceViewTeam,
            HrPermission::AttendanceViewAll,
            HrPermission::LeaveViewOwn,
            HrPermission::LeaveApprove,
            HrPermission::WarningViewOwn,
            HrPermission::NoticeView,
        ] as $permission) {
            if ($this->hr->allows($user, $permission)) {
                return true;
            }
        }

        return false;
    }
}
