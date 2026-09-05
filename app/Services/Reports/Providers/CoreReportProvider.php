<?php

namespace App\Services\Reports\Providers;

use App\Contracts\Reports\ReportProvider;
use App\DTOs\Reports\ReportDefinition;
use App\Enums\ComplaintPermission;
use App\Enums\CustomerReturnPermission;
use App\Enums\HrPermission;
use App\Enums\InventoryPermission;
use App\Enums\OrderPermission;
use App\Enums\PerformancePermission;
use App\Enums\PurchasePermission;
use App\Enums\SafetClaimPermission;
use App\Enums\TaskPermission;
use App\Enums\WarrantyRepairPermission;
use App\Models\User;
use App\Services\Authorization\ComplaintAuthorization;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Authorization\HrAuthorization;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\PerformanceAuthorization;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\Authorization\SafetClaimAuthorization;
use App\Services\Authorization\TaskAuthorization;
use App\Services\Authorization\WarrantyRepairAuthorization;
use App\Services\Reports\ReportQueryService;

class CoreReportProvider implements ReportProvider
{
    public function __construct(
        private readonly OrderAuthorization $orders,
        private readonly InventoryAuthorization $inventory,
        private readonly PurchaseAuthorization $purchases,
        private readonly CustomerReturnAuthorization $returns,
        private readonly SafetClaimAuthorization $claims,
        private readonly WarrantyRepairAuthorization $warranty,
        private readonly ComplaintAuthorization $complaints,
        private readonly TaskAuthorization $tasks,
        private readonly HrAuthorization $hr,
        private readonly PerformanceAuthorization $performance,
    ) {}

    public function definitions(): iterable
    {
        return [
            $this->definition('orders', 'Orders Report', 'Sales & Orders', 'orders', true, ['status', 'platform', 'product'], 100),
            $this->definition('sales_summary', 'Sales Summary', 'Sales & Orders', 'orders', true, ['platform'], 110, 'salesSummary'),
            $this->definition('product_sales', 'Product Sales', 'Sales & Orders', 'orders', true, ['platform', 'product', 'brand'], 120, 'productSales'),
            $this->definition('platform_sales', 'Platform Sales', 'Sales & Orders', 'orders', true, ['platform'], 130, 'salesSummary'),
            $this->definition('current_inventory', 'Current Inventory', 'Inventory', 'inventory', false, ['warehouse', 'product', 'brand'], 200, 'inventory', ['current_inventory'], true),
            $this->definition('low_stock', 'Low Stock', 'Inventory', 'inventory', false, ['warehouse', 'product', 'brand'], 210, 'inventory', ['low_stock'], true),
            $this->definition('out_of_stock', 'Out of Stock', 'Inventory', 'inventory', false, ['warehouse', 'product', 'brand'], 220, 'inventory', ['out_of_stock'], true),
            $this->definition('damaged_stock', 'Damaged Stock', 'Inventory', 'inventory', false, ['warehouse', 'product'], 230, 'inventory', ['damaged_stock'], true),
            $this->definition('qc_pending', 'QC Pending', 'Inventory', 'inventory', false, ['warehouse', 'product'], 240, 'inventory', ['qc_pending'], true),
            $this->definition('stock_movements', 'Stock Movement History', 'Inventory', 'movements', true, ['warehouse', 'product', 'status'], 250, 'movements'),
            $this->definition('purchases', 'Purchase Orders', 'Purchasing', 'purchases', true, ['status', 'supplier', 'warehouse', 'product'], 300, 'purchases', [false], true),
            $this->definition('purchase_receiving', 'Purchase Receiving / GRNs', 'Purchasing', 'purchases', true, ['supplier', 'warehouse', 'product'], 310, 'receiving', [], true),
            $this->definition('outstanding_purchases', 'Outstanding Purchase Quantities', 'Purchasing', 'purchases', false, ['supplier', 'warehouse', 'product'], 320, 'purchases', [true], true),
            $this->definition('returns', 'Customer Returns', 'Returns / Claims / Service', 'returns', true, ['status', 'platform', 'product'], 400),
            $this->definition('claims', 'Safe-T Claims', 'Returns / Claims / Service', 'claims', true, ['status', 'platform', 'product'], 410, 'claims', [], true),
            $this->definition('refund_exposure', 'Refund Exposure', 'Returns / Claims / Service', 'refunds', true, ['status', 'platform', 'product'], 420, 'refundExposure', [], true),
            $this->definition('warranty', 'Warranty / Repair', 'Returns / Claims / Service', 'warranty', true, ['status', 'platform', 'product'], 430, 'warranty', [false]),
            $this->definition('complaints', 'Complaints', 'Returns / Claims / Service', 'complaints', true, ['status', 'platform', 'product'], 440),
            $this->definition('internal_repairs', 'Internal Repairs', 'Returns / Claims / Service', 'warranty', true, ['status', 'product'], 450, 'warranty', [true]),
            $this->definition('tasks', 'Tasks', 'Tasks / Operations', 'tasks', true, ['status', 'employee', 'team'], 500),
            $this->definition('attendance', 'Attendance', 'HR', 'attendance', true, ['status', 'employee', 'team'], 600),
            $this->definition('leave', 'Leave', 'HR', 'leave', true, ['status', 'employee', 'team'], 610),
            $this->definition('comp_off', 'Comp Off', 'HR', 'leave', true, ['status', 'employee'], 620, 'compOff'),
            $this->definition('warnings', 'Warnings', 'HR', 'warnings', true, ['status', 'employee', 'team'], 630),
            $this->definition('notices', 'Notices / Acknowledgments', 'HR', 'notices', true, ['status'], 640),
            $this->definition('performance', 'Performance Matrix', 'HR', 'performance', true, ['employee', 'team'], 650, 'performance', [], true),
        ];
    }

    /** @param array<int, string> $filters @param array<int, mixed> $arguments */
    private function definition(string $key, string $title, string $group, string $permission, bool $period, array $filters, int $order, ?string $method = null, array $arguments = [], bool $financial = false): ReportDefinition
    {
        return new ReportDefinition(
            key: $key,
            title: $title,
            group: $group,
            handler: ReportQueryService::class,
            handlerMethod: $method ?? $key,
            period: $period,
            filters: $filters,
            formats: ['xlsx', 'csv', 'pdf'],
            order: $order,
            financialSensitive: $financial,
            viewAuthorization: fn (User $user): bool => $this->allowed($user, $permission),
            exportAuthorization: fn (User $user): bool => $this->canExport($user, $permission),
            handlerArguments: $arguments,
            pdfColumns: $this->pdfColumns($key),
        );
    }

    /** @return array<int, string> */
    private function pdfColumns(string $key): array
    {
        return match ($key) {
            'orders' => ['order_reference', 'order_date', 'platform', 'status', 'sku', 'product', 'quantity', 'selling_price', 'line_total'],
            'current_inventory', 'low_stock', 'out_of_stock' => ['sku', 'product', 'brand', 'location', 'available', 'reserved', 'sellable', 'damaged', 'qc_pending', 'inventory_value'],
            'stock_movements' => ['reference', 'occurred_at', 'sku', 'product', 'location', 'movement_type', 'quantity', 'available_delta', 'damaged_delta', 'reason'],
            'purchases', 'outstanding_purchases' => ['purchase_reference', 'purchase_date', 'supplier', 'location', 'status', 'sku', 'product', 'ordered_quantity', 'received_quantity', 'outstanding_quantity', 'line_total'],
            'claims' => ['claim_reference', 'return_reference', 'platform', 'sku', 'product', 'status', 'filed_at', 'claimed_amount', 'approved_amount', 'reimbursed_amount', 'currency'],
            'warranty', 'internal_repairs' => ['warranty_reference', 'sku', 'product', 'quantity', 'platform', 'received_at', 'expected_return_at', 'completed_at', 'status', 'service_provider', 'assigned_to'],
            default => [],
        };
    }

    private function canExport(User $user, string $permission): bool
    {
        return match ($permission) {
            'orders' => $this->orders->allows($user, OrderPermission::Export),
            'inventory', 'movements' => $this->inventory->allows($user, InventoryPermission::Export),
            'purchases' => $this->purchases->allows($user, PurchasePermission::Export),
            default => $this->allowed($user, $permission),
        };
    }

    private function allowed(User $user, string $permission): bool
    {
        return match ($permission) {
            'orders' => $this->orders->allows($user, OrderPermission::View),
            'inventory' => $this->inventory->allows($user, InventoryPermission::View),
            'movements' => $this->inventory->allows($user, InventoryPermission::ViewMovements),
            'purchases' => $this->purchases->allows($user, PurchasePermission::View),
            'returns' => $this->returns->allows($user, CustomerReturnPermission::View),
            'refunds' => $this->returns->allows($user, CustomerReturnPermission::View)
                && $this->returns->allows($user, CustomerReturnPermission::ViewRefundAmount),
            'claims' => $this->claims->allows($user, SafetClaimPermission::View),
            'warranty' => $this->warranty->allows($user, WarrantyRepairPermission::View),
            'complaints' => $this->complaints->allows($user, ComplaintPermission::View),
            'tasks' => $this->tasks->allows($user, TaskPermission::View),
            'attendance' => $this->anyHr($user, HrPermission::AttendanceViewOwn, HrPermission::AttendanceViewTeam, HrPermission::AttendanceViewAll),
            'leave' => $this->anyHr($user, HrPermission::LeaveViewOwn, HrPermission::LeaveViewTeam, HrPermission::LeaveViewAll),
            'warnings' => $this->anyHr($user, HrPermission::WarningViewOwn, HrPermission::WarningViewTeam, HrPermission::WarningViewAll),
            'notices' => $this->hr->allows($user, HrPermission::NoticeView),
            'performance' => collect(PerformancePermission::cases())->contains(fn (PerformancePermission $case): bool => $this->performance->allows($user, $case)),
            default => false,
        };
    }

    private function anyHr(User $user, HrPermission ...$permissions): bool
    {
        return collect($permissions)->contains(fn (HrPermission $permission): bool => $this->hr->allows($user, $permission));
    }
}
