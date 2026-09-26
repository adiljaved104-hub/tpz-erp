<?php

namespace App\Services\Mobile;

use App\Enums\ChatPermission;
use App\Enums\ComplaintPermission;
use App\Enums\CustomerReturnPermission;
use App\Enums\HrPermission;
use App\Enums\InventoryLocationPermission;
use App\Enums\InventoryPermission;
use App\Enums\InvoicePermission;
use App\Enums\OrderPermission;
use App\Enums\ProductPermission;
use App\Enums\PurchasePermission;
use App\Enums\ResponsibilityPermission;
use App\Enums\SafetClaimPermission;
use App\Enums\StockTransferPermission;
use App\Enums\TaskPermission;
use App\Enums\WarrantyRepairPermission;
use App\Models\Employee;
use App\Models\Team;
use App\Models\User;
use App\Services\Authorization\ChatAuthorization;
use App\Services\Authorization\ComplaintAuthorization;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Authorization\HrAuthorization;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\InventoryLocationAuthorization;
use App\Services\Authorization\InvoiceAuthorization;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\ProductAuthorization;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\Authorization\ResponsibilityAuthorization;
use App\Services\Authorization\SafetClaimAuthorization;
use App\Services\Authorization\StockTransferAuthorization;
use App\Services\Authorization\TaskAuthorization;
use App\Services\Authorization\WarrantyRepairAuthorization;
use App\Services\Reports\ReportCatalog;

class MobileWorkspaceCapabilities
{
    public function modules(User $user): array
    {
        $inventory = app(InventoryAuthorization::class)->allows($user, InventoryPermission::View)
            || app(ResponsibilityAuthorization::class)->allows($user, ResponsibilityPermission::ViewOwn);
        $orders = app(OrderAuthorization::class)->allows($user, OrderPermission::View);
        $products = app(ProductAuthorization::class)->allows($user, ProductPermission::View)
            || app(ResponsibilityAuthorization::class)->allows($user, ResponsibilityPermission::ViewOwn);
        $purchases = app(PurchaseAuthorization::class)->allows($user, PurchasePermission::View);
        $suppliers = app(PurchaseAuthorization::class)->allows($user, PurchasePermission::SupplierView);
        $locations = app(InventoryLocationAuthorization::class)->allows($user, InventoryLocationPermission::View);
        $transfers = app(StockTransferAuthorization::class)->allows($user, StockTransferPermission::View);
        $invoices = app(InvoiceAuthorization::class)->allows($user, InvoicePermission::View);
        $reports = app(ReportCatalog::class)->available($user) !== [];
        $returns = app(CustomerReturnAuthorization::class)->allows($user, CustomerReturnPermission::View);
        $warranty = app(WarrantyRepairAuthorization::class)->allows($user, WarrantyRepairPermission::View);
        $tasks = app(TaskAuthorization::class)->allows($user, TaskPermission::View);
        $responsibilities = app(ResponsibilityAuthorization::class)->allows($user, ResponsibilityPermission::ViewOwn)
            || app(ResponsibilityAuthorization::class)->allows($user, ResponsibilityPermission::ViewTeam)
            || app(ResponsibilityAuthorization::class)->allows($user, ResponsibilityPermission::ViewAll);
        $hr = app(HrAuthorization::class);
        $hrVisible = $user->can('viewAny', Employee::class) || $user->can('view', $user->employee)
            || $user->can('viewAny', Team::class)
            || $hr->allows($user, HrPermission::NoticeView)
            || $hr->allows($user, HrPermission::WarningViewOwn)
            || $hr->allows($user, HrPermission::WarningViewTeam)
            || $hr->allows($user, HrPermission::WarningViewAll);

        return array_values(array_filter([
            $this->module('sales', 'Sales', 'Create and manage authorized sales', 'SALE', $orders),
            $this->module('inventory', 'My Inventory', 'Assigned stock and inventory', 'INV', $inventory),
            $this->module('products', 'Products', 'Search products and SKUs', 'PRD', $products),
            $this->module('purchases', 'Purchases', 'Purchases and receipts', 'PUR', $purchases),
            $this->module('suppliers', 'Suppliers', 'Authorized supplier contacts', 'SUP', $suppliers),
            $this->module('inventory_locations', 'Locations', 'Warehouses and inventory locations', 'LOC', $locations),
            $this->module('stock_transfers', 'Stock Transfers', 'Authorized stock movements', 'TRN', $transfers),
            $this->module('reservations', 'Reservations', 'Authorized inventory reservations', 'RSV', $inventory),
            $this->module('invoices', 'Invoices', 'Authorized tax invoices', 'INVX', $invoices),
            $this->module('reports', 'Reports', 'Authorized ERP reports and analytics', 'RPT', $reports),
            $this->module('hr', 'HR', 'People, notices and warnings', 'HR', $hrVisible),
            $this->module('responsibilities', 'Responsibilities', 'Your assigned product scope', 'RSP', $responsibilities),
            $this->module('returns', 'Returns', 'Authorized customer returns', 'RET', $returns),
            $this->module('warranty', 'Warranty', 'Authorized service cases', 'WAR', $warranty),
            $this->module('internal_repairs', 'Internal Repairs', 'Company-owned repair cases', 'REP', $warranty),
            $this->module('claims', 'Claims', 'Safe-T claims in your scope', 'CLM', app(SafetClaimAuthorization::class)->allows($user, SafetClaimPermission::View)),
            $this->module('complaints', 'Complaints', 'Unresolved customer cases', 'CMP', app(ComplaintAuthorization::class)->allows($user, ComplaintPermission::View)),
            $this->module('tasks', 'Tasks', 'Assigned work and follow-ups', 'TSK', $tasks),
            $this->module('notifications', 'Notifications', 'Important ERP alerts', 'ALT', true),
            $this->module('chat', 'Chat', 'Direct and team conversations', 'CHAT', app(ChatAuthorization::class)->allows($user, ChatPermission::View)),
        ]));
    }

    public function has(User $user, string $key): bool
    {
        return collect($this->modules($user))->contains('key', $key);
    }

    private function module(string $key, string $title, string $description, string $code, bool $visible): ?array
    {
        return $visible ? compact('key', 'title', 'description', 'code') : null;
    }
}
