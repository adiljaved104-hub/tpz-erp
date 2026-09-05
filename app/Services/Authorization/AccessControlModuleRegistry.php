<?php

namespace App\Services\Authorization;

use App\Enums\AuthSecurityPermission;
use App\Enums\BackupSettingsPermission;
use App\Enums\CatalogPermission;
use App\Enums\ChatPermission;
use App\Enums\CompanyProfilePermission;
use App\Enums\ComplaintPermission;
use App\Enums\ComponentPermission;
use App\Enums\CustomerReturnPermission;
use App\Enums\DamagedStockPermission;
use App\Enums\EmailSettingsPermission;
use App\Enums\ExpensePermission;
use App\Enums\HrPermission;
use App\Enums\InventoryLocationPermission;
use App\Enums\InventoryPermission;
use App\Enums\InvoicePermission;
use App\Enums\MarketplaceReturnPermission;
use App\Enums\NotificationRulePermission;
use App\Enums\OfficeFinancePermission;
use App\Enums\OrderPermission;
use App\Enums\PeoplePermission;
use App\Enums\PerformancePermission;
use App\Enums\ProductPermission;
use App\Enums\PurchasePermission;
use App\Enums\QuotationPermission;
use App\Enums\ResponsibilityPermission;
use App\Enums\SafetClaimPermission;
use App\Enums\StockTransferPermission;
use App\Enums\TaskPermission;
use App\Enums\UpgradePermission;
use App\Enums\WarrantyRepairPermission;
use App\Enums\WebSalesPermission;
use LogicException;

/**
 * Presentation definitions for the Access Control screen.
 *
 * The registry never authorizes a request. It maps the existing granular
 * permission catalog into a simpler module-based editor.
 */
class AccessControlModuleRegistry
{
    public function __construct(private readonly EmployeePermissionCatalog $catalog) {}

    /** @return array<string, array{label: string, description: string}> */
    public function groups(): array
    {
        return [
            'dashboard' => ['label' => 'Dashboard', 'description' => 'Personal work and dashboard access derived from source modules.'],
            'people_hr' => ['label' => 'People & HR', 'description' => 'Employees, attendance, leave, schedules, warnings, notices, and performance.'],
            'products_inventory' => ['label' => 'Products & Inventory', 'description' => 'Catalog, inventory, locations, transfers, and responsibilities.'],
            'purchasing' => ['label' => 'Purchasing', 'description' => 'Suppliers, purchases, receipts, and purchasing controls.'],
            'sales_orders' => ['label' => 'Sales & Orders', 'description' => 'Sales orders and their operational controls.'],
            'web_sales' => ['label' => 'Web Sales', 'description' => 'Direct-order workflow with separately protected financial visibility.'],
            'quotations_invoices' => ['label' => 'Quotations & Invoices', 'description' => 'Quotations, proformas, Tax Invoices, and invoice settings.'],
            'returns_service' => ['label' => 'Returns / Claims / Warranty', 'description' => 'Returns, claims, complaints, warranty, and internal repairs.'],
            'finance' => ['label' => 'Finance', 'description' => 'AED business expenses and PKR Pakistan Office Finance remain separate.'],
            'tasks_chat' => ['label' => 'Tasks & Chat', 'description' => 'Tasks, My Work, assignments, and Internal Chat.'],
            'reports' => ['label' => 'Reports', 'description' => 'Report access is derived from report and source-module authorization.'],
            'administration' => ['label' => 'Administration & Settings', 'description' => 'Company, email, notifications, login security, backups, and audit logs.'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function modules(): array
    {
        $modules = [
            $this->derived('dashboard', 'My Dashboard & My Work', 'dashboard', 'Access follows the employee’s authorized source modules and personal Task assignments.'),

            $this->module('employees', 'Employees', 'people_hr', [PeoplePermission::EmployeeView], [PeoplePermission::EmployeeCreate, PeoplePermission::EmployeeUpdate], [PeoplePermission::EmployeeChangeRole, PeoplePermission::EmployeeChangeStatus, PeoplePermission::EmployeeLinkUser, PeoplePermission::EmployeeUnlinkUser]),
            $this->module('teams', 'Teams', 'people_hr', [PeoplePermission::TeamView], [PeoplePermission::TeamManage]),
            $this->module('attendance', 'Attendance', 'people_hr', [HrPermission::AttendanceViewOwn], [HrPermission::AttendanceCorrect], [HrPermission::AttendanceViewTeam, HrPermission::AttendanceViewAll, HrPermission::AttendanceManagePolicy]),
            $this->module('leave', 'Leave', 'people_hr', [HrPermission::LeaveViewOwn], [HrPermission::LeaveRequest], [HrPermission::LeaveViewTeam, HrPermission::LeaveViewAll, HrPermission::LeaveApprove, HrPermission::LeaveManagePolicy]),
            $this->module('schedules', 'Work Schedules, Holidays & Comp Off', 'people_hr', [], [HrPermission::WorkScheduleManage], [], 'The existing permission controls schedule, holiday, and compensatory-off administration together.'),
            $this->module('biometric', 'Biometric Sync', 'people_hr', [], [HrPermission::BiometricManage]),
            $this->module('warnings', 'Warnings', 'people_hr', [HrPermission::WarningViewOwn], [HrPermission::WarningIssue, HrPermission::WarningManage], [HrPermission::WarningViewTeam, HrPermission::WarningViewAll]),
            $this->module('notices', 'Notices', 'people_hr', [HrPermission::NoticeView], [HrPermission::NoticePublish, HrPermission::NoticeManage]),
            $this->module('performance', 'Performance', 'people_hr', [PerformancePermission::ViewOwn], [], [PerformancePermission::ViewTeam, PerformancePermission::ViewAll]),

            $this->module('products', 'Products', 'products_inventory', [ProductPermission::View], [ProductPermission::Create, ProductPermission::Update], [ProductPermission::ViewSellingPrice, ProductPermission::EditSellingPrice, ProductPermission::ViewCostPrice, ProductPermission::EditCostPrice, ProductPermission::Activate, ProductPermission::Deactivate, ProductPermission::Discontinue, ProductPermission::Reactivate, ProductPermission::Export]),
            $this->module('components', 'Upgrade Components', 'products_inventory', [ComponentPermission::View], [ComponentPermission::Create, ComponentPermission::Update, ComponentPermission::ChangeStatus], [ComponentPermission::ViewRecoveryValue, ComponentPermission::ApproveRecoveryValue], 'Component stock uses the existing Inventory and Purchasing permissions. OEM recovery values remain separately protected financial data.'),
            $this->module('sales_configurations', 'Hardware Profiles & Sales Configurations', 'products_inventory', [], [UpgradePermission::ManageHardwareProfiles, UpgradePermission::ManageConfigurations, UpgradePermission::ManageRecipes, UpgradePermission::ManageSellingAddons], [UpgradePermission::ApproveRecoveryOverride], 'Configuration selling fields and OEM recovery overrides remain separately protected.'),
            $this->module('brands', 'Product Brands', 'products_inventory', [CatalogPermission::BrandView], [CatalogPermission::BrandManage]),
            $this->module('categories', 'Product Categories', 'products_inventory', [CatalogPermission::CategoryView], [CatalogPermission::CategoryManage]),
            $this->module('inventory', 'Inventory & Opening Stock', 'products_inventory', [InventoryPermission::View], [InventoryPermission::PostOpeningStock, InventoryPermission::ReverseOpeningStock, InventoryPermission::Reserve, InventoryPermission::ReleaseReservation, InventoryPermission::MarkDamaged, InventoryPermission::RestoreDamaged], [InventoryPermission::ViewMovements, InventoryPermission::Export, InventoryPermission::ViewFinancials]),
            $this->module('locations', 'Warehouses & Inventory Locations', 'products_inventory', [InventoryLocationPermission::View], [InventoryLocationPermission::Manage]),
            $this->module('transfers', 'Stock Transfers', 'products_inventory', [StockTransferPermission::View], [StockTransferPermission::Create, StockTransferPermission::Dispatch, StockTransferPermission::Receive, StockTransferPermission::Cancel], [StockTransferPermission::ViewCost]),
            $this->module('responsibilities', 'Responsibility Assignments', 'products_inventory', [ResponsibilityPermission::ViewOwn], [ResponsibilityPermission::Assign, ResponsibilityPermission::Reassign, ResponsibilityPermission::Deactivate], [ResponsibilityPermission::ViewTeam, ResponsibilityPermission::ViewAll, ResponsibilityPermission::ViewHistory, ResponsibilityPermission::ManagePlatforms]),
            $this->module('damaged', 'Damaged Items', 'products_inventory', [DamagedStockPermission::View]),

            $this->module('suppliers', 'Suppliers', 'purchasing', [PurchasePermission::SupplierView], [PurchasePermission::SupplierManage]),
            $this->module('purchases', 'Purchases & Receipts', 'purchasing', [PurchasePermission::View], [PurchasePermission::Create, PurchasePermission::UpdateDraft], [PurchasePermission::ViewFinancials, PurchasePermission::ViewCostHistory, PurchasePermission::Approve, PurchasePermission::Cancel, PurchasePermission::Receive, PurchasePermission::Close, PurchasePermission::ViewReceipts, PurchasePermission::Export, PurchasePermission::QuickReceive]),

            $this->module('orders', 'Orders', 'sales_orders', [OrderPermission::View], [OrderPermission::Create, OrderPermission::UpdateDraft], [OrderPermission::Confirm, OrderPermission::Reserve, OrderPermission::Process, OrderPermission::Fulfill, OrderPermission::Cancel, OrderPermission::ViewSellingPrice, OrderPermission::EditSellingPrice, OrderPermission::ViewCost, OrderPermission::ViewProfit, OrderPermission::Export]),

            $this->module('web_sales', 'Web Sales', 'web_sales', [WebSalesPermission::View], [WebSalesPermission::Create, WebSalesPermission::Update], [WebSalesPermission::ViewAll, WebSalesPermission::ViewRevenue, WebSalesPermission::ViewCost, WebSalesPermission::ViewGrossProfit, WebSalesPermission::Cancel], 'Cost and profit remain independent financial permissions.'),

            $this->module('quotations', 'Quotations / Proforma', 'quotations_invoices', [QuotationPermission::View], [QuotationPermission::Create, QuotationPermission::Update], [QuotationPermission::ViewAll, QuotationPermission::Send, QuotationPermission::Accept, QuotationPermission::Reject, QuotationPermission::ConvertOrder, QuotationPermission::ConvertInvoice, QuotationPermission::Cancel, QuotationPermission::Export]),
            $this->module('tax_invoices', 'Tax Invoices', 'quotations_invoices', [InvoicePermission::View], [InvoicePermission::Create], [InvoicePermission::ViewAll, InvoicePermission::Void, InvoicePermission::DownloadPdf, InvoicePermission::Export], 'Tax Invoices are immutable after issue; no general update permission exists.'),
            $this->module('invoice_settings', 'Invoice Settings', 'quotations_invoices', [InvoicePermission::SettingsView], [InvoicePermission::SettingsManage]),

            $this->module('returns', 'Customer Returns', 'returns_service', [CustomerReturnPermission::View], [CustomerReturnPermission::Create, CustomerReturnPermission::Receive, CustomerReturnPermission::Inspect, CustomerReturnPermission::Cancel, CustomerReturnPermission::RecordMarketplaceDisposition], [CustomerReturnPermission::ViewRefundAmount, CustomerReturnPermission::RecordRefund]),
            $this->module('marketplace_returns', 'Marketplace Return Removals', 'returns_service', [MarketplaceReturnPermission::View], [MarketplaceReturnPermission::RequestRemoval, MarketplaceReturnPermission::DispatchToCompany, MarketplaceReturnPermission::ReceiveCompany]),
            $this->module('claims', 'Claims / Safe-T', 'returns_service', [SafetClaimPermission::View], [SafetClaimPermission::File, SafetClaimPermission::UpdateStatus, SafetClaimPermission::Close, SafetClaimPermission::Assign], [SafetClaimPermission::ViewFinancial, SafetClaimPermission::UpdateFinancial]),
            $this->module('warranty', 'Warranty & Internal Repairs', 'returns_service', [WarrantyRepairPermission::View], [WarrantyRepairPermission::Create, WarrantyRepairPermission::UpdateStatus, WarrantyRepairPermission::Assign, WarrantyRepairPermission::Receive, WarrantyRepairPermission::Inspect, WarrantyRepairPermission::MoveToDamaged]),
            $this->module('complaints', 'Complaints', 'returns_service', [ComplaintPermission::View], [ComplaintPermission::Create, ComplaintPermission::Update, ComplaintPermission::Assign, ComplaintPermission::Resolve]),

            $this->module('expenses', 'Business Expenses (AED)', 'finance', [ExpensePermission::View], [ExpensePermission::Create, ExpensePermission::Update], [ExpensePermission::ViewAll, ExpensePermission::ViewAmount, ExpensePermission::DeleteOrVoid, ExpensePermission::ViewNetProfit], 'UAE / Web Sales / Marketplace operating expenses. Financial fields remain separately protected.'),
            $this->module('office_finance', 'Pakistan Office Finance (PKR)', 'finance', [OfficeFinancePermission::View], [OfficeFinancePermission::Create, OfficeFinancePermission::Update], [OfficeFinancePermission::ViewAll, OfficeFinancePermission::ViewBalances, OfficeFinancePermission::ViewFunding, OfficeFinancePermission::ViewLoans, OfficeFinancePermission::ManageLoans, OfficeFinancePermission::Void, OfficeFinancePermission::Export], 'PKR office funding, expenses, employee loans, accounts, and cashbook.'),

            $this->module('tasks', 'Tasks', 'tasks_chat', [TaskPermission::View], [TaskPermission::Create, TaskPermission::Update, TaskPermission::ChangeStatus, TaskPermission::Complete], [TaskPermission::Assign, TaskPermission::Cancel, TaskPermission::ViewTeam, TaskPermission::ManageAll]),
            $this->derived('my_work', 'My Work', 'tasks_chat', 'Personal work access derives from Task access and each source module’s permission and Responsibility scope.'),
            $this->module('chat', 'Internal Chat & Channels', 'tasks_chat', [ChatPermission::View], [ChatPermission::Direct, ChatPermission::Team, ChatPermission::Context, ChatPermission::Thread, ChatPermission::React, ChatPermission::Search], [ChatPermission::Manage, ChatPermission::ChannelCreate, ChatPermission::ChannelManage, ChatPermission::ChannelArchive, ChatPermission::ChannelJoin, ChatPermission::Pin]),

            $this->derived('reports', 'Reports & Exports', 'reports', 'There is no standalone report permission. A report requires the existing source-module view/export permission and its normal scope; financial projections require their financial permission.'),

            $this->module('email_settings', 'Email Settings', 'administration', [EmailSettingsPermission::View], [EmailSettingsPermission::Manage], [EmailSettingsPermission::Test]),
            $this->module('notification_rules', 'Notification Rules', 'administration', [NotificationRulePermission::View], [NotificationRulePermission::Manage]),
            $this->module('company_profile', 'Company Profile', 'administration', [CompanyProfilePermission::View], [CompanyProfilePermission::Manage]),
            $this->module('login_security', 'Login & Security', 'administration', [AuthSecurityPermission::View], [AuthSecurityPermission::Manage], [AuthSecurityPermission::ManageTwoFactor]),
            $this->module('backup_settings', 'Backup Settings', 'administration', [BackupSettingsPermission::View], [BackupSettingsPermission::Manage], [BackupSettingsPermission::Run, BackupSettingsPermission::Verify, BackupSettingsPermission::Download]),
            $this->module('activity_logs', 'Activity Logs', 'administration', [PeoplePermission::ActivityLogView]),
        ];

        return array_map(fn (array $module): array => $this->hydrate($module), $modules);
    }

    /** @return array<string, array<string, mixed>> */
    public function keyed(): array
    {
        return collect($this->modules())->keyBy('key')->all();
    }

    /** @return array<int, string> */
    public function managedPermissionKeys(): array
    {
        return collect($this->modules())
            ->flatMap(fn (array $module): array => $module['permission_keys'])
            ->values()
            ->all();
    }

    /** @return array{missing: array<int, string>, unknown: array<int, string>, duplicates: array<int, string>} */
    public function reconcile(): array
    {
        $catalogKeys = collect($this->catalog->groups())->flatten(1)->pluck('key')->values();
        $managed = collect($this->managedPermissionKeys());

        return [
            'missing' => $catalogKeys->diff($managed)->values()->all(),
            'unknown' => $managed->diff($catalogKeys)->values()->all(),
            'duplicates' => $managed->duplicates()->unique()->values()->all(),
        ];
    }

    /** @param array<int, \BackedEnum> $view @param array<int, \BackedEnum> $edit @param array<int, \BackedEnum> $advanced */
    private function module(string $key, string $label, string $group, array $view, array $edit = [], array $advanced = [], string $description = ''): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'group' => $group,
            'description' => $description,
            'view_keys' => array_map(fn (\BackedEnum $permission): string => (string) $permission->value, $view),
            'edit_keys' => array_map(fn (\BackedEnum $permission): string => (string) $permission->value, $edit),
            'advanced_keys' => array_map(fn (\BackedEnum $permission): string => (string) $permission->value, $advanced),
            'derived' => false,
        ];
    }

    private function derived(string $key, string $label, string $group, string $description): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'group' => $group,
            'description' => $description,
            'view_keys' => [],
            'edit_keys' => [],
            'advanced_keys' => [],
            'derived' => true,
        ];
    }

    /** @param array<string, mixed> $module */
    private function hydrate(array $module): array
    {
        $module['view_permissions'] = $this->definitions($module['view_keys']);
        $module['edit_permissions'] = $this->definitions($module['edit_keys']);
        $module['advanced_permissions'] = $this->definitions($module['advanced_keys']);
        $module['permission_keys'] = [...$module['view_keys'], ...$module['edit_keys'], ...$module['advanced_keys']];

        if (count($module['permission_keys']) !== count(array_unique($module['permission_keys']))) {
            throw new LogicException("Duplicate permission within Access Control module [{$module['key']}].");
        }

        return $module;
    }

    /** @param array<int, string> $keys @return array<int, array{key: string, label: string, sensitive: bool, financial: bool}> */
    private function definitions(array $keys): array
    {
        return array_map(function (string $key): array {
            $definition = $this->catalog->find($key);

            if ($definition === null) {
                throw new LogicException("Unknown permission [{$key}] in the Access Control module registry.");
            }

            return $definition;
        }, $keys);
    }
}
