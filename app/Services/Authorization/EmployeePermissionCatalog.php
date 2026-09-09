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
use App\Models\Employee;
use App\Models\User;

class EmployeePermissionCatalog
{
    /** @return array<string, array<int, array{key: string, label: string, sensitive: bool, financial: bool}>> */
    public function groups(): array
    {
        return [
            'People & Access' => [
                $this->item(PeoplePermission::EmployeeView, 'View Employees'),
                $this->item(PeoplePermission::EmployeeCreate, 'Create Employees', true),
                $this->item(PeoplePermission::EmployeeUpdate, 'Update Employees', true),
                $this->item(PeoplePermission::EmployeeChangeRole, 'Change Employee Roles', true),
                $this->item(PeoplePermission::EmployeeChangeStatus, 'Activate / Deactivate Employees', true),
                $this->item(PeoplePermission::EmployeeLinkUser, 'Link Login Accounts', true),
                $this->item(PeoplePermission::EmployeeUnlinkUser, 'Unlink Login Accounts', true),
                $this->item(PeoplePermission::TeamView, 'View Teams'),
                $this->item(PeoplePermission::TeamManage, 'Manage Teams', true),
                $this->item(PeoplePermission::ActivityLogView, 'View Activity Logs', true),
                $this->item(EmailSettingsPermission::View, 'View Email Settings', true),
                $this->item(EmailSettingsPermission::Manage, 'Manage Email Settings', true),
                $this->item(EmailSettingsPermission::Test, 'Test Email Delivery', true),
                $this->item(NotificationRulePermission::View, 'View Notification Rules', true),
                $this->item(NotificationRulePermission::Manage, 'Manage Notification Rules', true),
                $this->item(CompanyProfilePermission::View, 'View Company Profile', true),
                $this->item(CompanyProfilePermission::Manage, 'Manage Company Profile', true),
                $this->item(AuthSecurityPermission::View, 'View Login & Security Settings', true),
                $this->item(AuthSecurityPermission::Manage, 'Manage Login & Security Settings', true),
                $this->item(AuthSecurityPermission::ManageTwoFactor, 'Manage Employee Two-Factor Authentication', true),
                $this->item(BackupSettingsPermission::View, 'View Backup Settings', true),
                $this->item(BackupSettingsPermission::Manage, 'Manage Backup Settings', true),
                $this->item(BackupSettingsPermission::Run, 'Run Backups', true),
                $this->item(BackupSettingsPermission::Verify, 'Verify Backups', true),
                $this->item(BackupSettingsPermission::Download, 'Download Backups', true),
            ],
            'Attendance & Leave' => [
                $this->item(HrPermission::AttendanceViewOwn, 'View Own Attendance'),
                $this->item(HrPermission::AttendanceViewTeam, 'View Team Attendance'),
                $this->item(HrPermission::AttendanceViewAll, 'View All Attendance', true),
                $this->item(HrPermission::AttendanceCorrect, 'Correct Attendance', true),
                $this->item(HrPermission::AttendanceManagePolicy, 'Manage Attendance Policy', true),
                $this->item(HrPermission::BiometricManage, 'Manage Biometric Sync', true),
                $this->item(HrPermission::LeaveViewOwn, 'View Own Leave'),
                $this->item(HrPermission::LeaveRequest, 'Request Leave'),
                $this->item(HrPermission::LeaveViewTeam, 'View Team Leave'),
                $this->item(HrPermission::LeaveViewAll, 'View All Leave', true),
                $this->item(HrPermission::LeaveApprove, 'Approve Leave', true),
                $this->item(HrPermission::LeaveManagePolicy, 'Manage Leave Policy', true),
                $this->item(HrPermission::WorkScheduleManage, 'Manage Work Schedules', true),
                $this->item(HrPermission::WarningViewOwn, 'View Own Warnings'),
                $this->item(HrPermission::WarningViewTeam, 'View Team Warnings', true),
                $this->item(HrPermission::WarningViewAll, 'View All Warnings', true),
                $this->item(HrPermission::WarningIssue, 'Issue Warnings', true),
                $this->item(HrPermission::WarningManage, 'Manage Warnings', true),
                $this->item(HrPermission::NoticeView, 'View Notices'),
                $this->item(HrPermission::NoticePublish, 'Publish Notices', true),
                $this->item(HrPermission::NoticeManage, 'Manage Notices', true),
                $this->item(PerformancePermission::ViewOwn, 'View Own Performance'),
                $this->item(PerformancePermission::ViewTeam, 'View Team Performance'),
                $this->item(PerformancePermission::ViewAll, 'View All Performance', true),
            ],
            'Catalog' => [
                $this->item(CatalogPermission::BrandView, 'View Brands'),
                $this->item(CatalogPermission::BrandManage, 'Manage Brands', true),
                $this->item(CatalogPermission::CategoryView, 'View Categories'),
                $this->item(CatalogPermission::CategoryManage, 'Manage Categories', true),
            ],
            'Orders' => [
                $this->item(OrderPermission::View, 'View Orders'),
                $this->item(OrderPermission::Create, 'Create Orders'),
                $this->item(OrderPermission::UpdateDraft, 'Update Draft Orders'),
                $this->item(OrderPermission::Confirm, 'Confirm Orders', true),
                $this->item(OrderPermission::Reserve, 'Reserve Stock'),
                $this->item(OrderPermission::Process, 'Process Orders', true),
                $this->item(OrderPermission::Fulfill, 'Ship Orders', true),
                $this->item(OrderPermission::Cancel, 'Cancel Orders', true),
                $this->item(OrderPermission::ViewSellingPrice, 'View Selling Price'),
                $this->item(OrderPermission::EditSellingPrice, 'Edit Selling Price', true),
                $this->item(OrderPermission::ViewCost, 'View Cost', true, true),
                $this->item(OrderPermission::ViewProfit, 'View Profit', true, true),
                $this->item(OrderPermission::Export, 'Export Orders'),
                $this->item(WebSalesPermission::View, 'View Web Sales'),
                $this->item(WebSalesPermission::Create, 'Create Web Sales'),
                $this->item(WebSalesPermission::Update, 'Update Web Sales', true),
                $this->item(WebSalesPermission::ViewAll, 'View All Web Sales', true),
                $this->item(WebSalesPermission::ViewRevenue, 'View Web Sales Revenue'),
                $this->item(WebSalesPermission::ViewCost, 'View Web Sales Cost', true, true),
                $this->item(WebSalesPermission::ViewGrossProfit, 'View Web Sales Gross Profit', true, true),
                $this->item(WebSalesPermission::Cancel, 'Cancel Web Sales', true),
                $this->item(InvoicePermission::View, 'View Own Invoices'),
                $this->item(InvoicePermission::ViewAll, 'View All Invoices', true),
                $this->item(InvoicePermission::Create, 'Create Tax Invoices'),
                $this->item(InvoicePermission::Void, 'Void Tax Invoices', true),
                $this->item(InvoicePermission::DownloadPdf, 'Download Invoice PDF'),
                $this->item(InvoicePermission::Export, 'Export Invoices'),
                $this->item(InvoicePermission::SettingsView, 'View Invoice Settings', true),
                $this->item(InvoicePermission::SettingsManage, 'Manage Invoice Settings', true),
                $this->item(QuotationPermission::View, 'View Quotations'),
                $this->item(QuotationPermission::Create, 'Create Quotations'),
                $this->item(QuotationPermission::Update, 'Update Draft Quotations'),
                $this->item(QuotationPermission::ViewAll, 'View All Quotations', true),
                $this->item(QuotationPermission::Send, 'Send Quotations'),
                $this->item(QuotationPermission::Accept, 'Accept Quotations', true),
                $this->item(QuotationPermission::Reject, 'Reject Quotations', true),
                $this->item(QuotationPermission::ConvertOrder, 'Convert Quotations to Orders', true),
                $this->item(QuotationPermission::ConvertInvoice, 'Convert Quotations to Invoices', true),
                $this->item(QuotationPermission::Cancel, 'Cancel Quotations', true),
                $this->item(QuotationPermission::Export, 'Export Quotations'),
                $this->item(QuotationPermission::SourceInventory, 'Source Inventory for Quotations', true),
                $this->item(QuotationPermission::ViewSourceCost, 'View Quotation Source Cost', true, true),
            ],
            'Purchasing' => [
                $this->item(PurchasePermission::SupplierView, 'View Suppliers'),
                $this->item(PurchasePermission::SupplierManage, 'Manage Suppliers', true),
                $this->item(PurchasePermission::View, 'View Purchases'),
                $this->item(PurchasePermission::Create, 'Create Purchases'),
                $this->item(PurchasePermission::UpdateDraft, 'Update Draft Purchases'),
                $this->item(PurchasePermission::ViewFinancials, 'View Purchase Totals', true),
                $this->item(PurchasePermission::ViewCostHistory, 'View Product Purchase Cost History', true, true),
                $this->item(PurchasePermission::Approve, 'Approve Purchases', true),
                $this->item(PurchasePermission::Cancel, 'Cancel Purchases', true),
                $this->item(PurchasePermission::Receive, 'Receive Purchases', true),
                $this->item(PurchasePermission::Close, 'Close Purchases', true),
                $this->item(PurchasePermission::ViewReceipts, 'View GRNs'),
                $this->item(PurchasePermission::Export, 'Export Purchases'),
                $this->item(PurchasePermission::QuickReceive, 'Quick Stock Purchase', true),
            ],
            'Finance' => [
                $this->item(ExpensePermission::View, 'View Expenses', true, true),
                $this->item(ExpensePermission::Create, 'Create Expenses', true, true),
                $this->item(ExpensePermission::Update, 'Update Expenses', true, true),
                $this->item(ExpensePermission::ViewAll, 'View All Expenses', true, true),
                $this->item(ExpensePermission::ViewAmount, 'View Expense Amounts', true, true),
                $this->item(ExpensePermission::DeleteOrVoid, 'Void Expenses', true, true),
                $this->item(ExpensePermission::ViewNetProfit, 'View Net Profit', true, true),
                $this->item(OfficeFinancePermission::View, 'View Pakistan Office Finance', true, true),
                $this->item(OfficeFinancePermission::Create, 'Post Pakistan Office Transactions', true, true),
                $this->item(OfficeFinancePermission::Update, 'Manage Pakistan Office Accounts', true, true),
                $this->item(OfficeFinancePermission::ViewAll, 'View All Pakistan Office Transactions', true, true),
                $this->item(OfficeFinancePermission::ViewBalances, 'View Pakistan Office Balances', true, true),
                $this->item(OfficeFinancePermission::ViewFunding, 'View Dubai Funding', true, true),
                $this->item(OfficeFinancePermission::ViewLoans, 'View Employee Loans', true, true),
                $this->item(OfficeFinancePermission::ManageLoans, 'Manage Employee Loans', true, true),
                $this->item(OfficeFinancePermission::Void, 'Void Pakistan Office Transactions', true, true),
                $this->item(OfficeFinancePermission::Export, 'Export Pakistan Office Finance', true, true),
            ],
            'Products / Costs' => [
                $this->item(ComponentPermission::View, 'View Upgrade Components'),
                $this->item(ComponentPermission::Create, 'Create Upgrade Components', true),
                $this->item(ComponentPermission::Update, 'Update Upgrade Components', true),
                $this->item(ComponentPermission::ChangeStatus, 'Activate / Deactivate Upgrade Components', true),
                $this->item(ComponentPermission::ViewRecoveryValue, 'View Approved OEM Recovery Values', true, true),
                $this->item(ComponentPermission::ApproveRecoveryValue, 'Approve OEM Recovery Values', true, true),
                $this->item(UpgradePermission::ManageHardwareProfiles, 'Manage Product Hardware Profiles', true),
                $this->item(UpgradePermission::ManageConfigurations, 'Manage Sales Configurations', true),
                $this->item(UpgradePermission::ManageRecipes, 'Manage Upgrade Recipes', true),
                $this->item(UpgradePermission::ManageSellingAddons, 'Manage Configuration Selling Add-ons', true),
                $this->item(UpgradePermission::ApproveRecoveryOverride, 'Approve Recipe Recovery Overrides', true, true),
                $this->item(ProductPermission::View, 'View Products'),
                $this->item(ProductPermission::Create, 'Create Products'),
                $this->item(ProductPermission::Update, 'Update Products'),
                $this->item(ProductPermission::ViewCostPrice, 'View Product Cost', true, true),
                $this->item(ProductPermission::EditCostPrice, 'Edit Product Cost', true, true),
                $this->item(ProductPermission::ViewSellingPrice, 'View Product Selling Price'),
                $this->item(ProductPermission::EditSellingPrice, 'Edit Product Selling Price', true),
                $this->item(ProductPermission::Activate, 'Activate Products', true),
                $this->item(ProductPermission::Deactivate, 'Deactivate Products', true),
                $this->item(ProductPermission::Discontinue, 'Discontinue Products', true),
                $this->item(ProductPermission::Reactivate, 'Reactivate Products', true),
                $this->item(ProductPermission::Export, 'Export Products'),
                $this->item(InventoryPermission::ViewFinancials, 'View Average Cost', true, true),
            ],
            'Inventory' => [
                $this->item(InventoryPermission::View, 'View Inventory'),
                $this->item(InventoryPermission::PostOpeningStock, 'Post Opening Stock', true),
                $this->item(InventoryPermission::ReverseOpeningStock, 'Reverse Opening Stock', true),
                $this->item(InventoryPermission::Reserve, 'Reserve Inventory', true),
                $this->item(InventoryPermission::ReleaseReservation, 'Release Reservations', true),
                $this->item(InventoryPermission::MarkDamaged, 'Move Stock to Damaged', true),
                $this->item(InventoryPermission::RestoreDamaged, 'Restore Damaged Stock', true),
                $this->item(InventoryPermission::ViewMovements, 'View Stock Movements'),
                $this->item(InventoryPermission::Export, 'Export Inventory'),
            ],
            'Responsibility' => [
                $this->item(ResponsibilityPermission::ViewAll, 'View All Responsibility Assignments'),
                $this->item(ResponsibilityPermission::ViewTeam, 'View Team Responsibilities'),
                $this->item(ResponsibilityPermission::ViewOwn, 'View Own Responsibilities'),
                $this->item(ResponsibilityPermission::Assign, 'Create Responsibility Assignments', true),
                $this->item(ResponsibilityPermission::Reassign, 'Transfer Responsibility Assignments', true),
                $this->item(ResponsibilityPermission::Deactivate, 'Deactivate Responsibility Assignments', true),
                $this->item(ResponsibilityPermission::ViewHistory, 'View Responsibility History'),
                $this->item(ResponsibilityPermission::ManagePlatforms, 'Manage Marketplace Platforms', true),
            ],
            'Inventory Locations' => [
                $this->item(InventoryLocationPermission::View, 'View Locations'),
                $this->item(InventoryLocationPermission::Manage, 'Manage Locations', true),
            ],
            'Stock Transfers' => [
                $this->item(StockTransferPermission::View, 'View Transfers'),
                $this->item(StockTransferPermission::Create, 'Create Transfers'),
                $this->item(StockTransferPermission::Dispatch, 'Dispatch Transfers', true),
                $this->item(StockTransferPermission::Receive, 'Receive Transfers', true),
                $this->item(StockTransferPermission::Cancel, 'Cancel / Return Transfers', true),
                $this->item(StockTransferPermission::ViewCost, 'View Transfer Cost', true, true),
            ],
            'Returns' => [
                $this->item(CustomerReturnPermission::View, 'View Returns'),
                $this->item(CustomerReturnPermission::Create, 'Create Returns'),
                $this->item(CustomerReturnPermission::Receive, 'Receive Returns', true),
                $this->item(CustomerReturnPermission::Inspect, 'Inspect Returns', true),
                $this->item(CustomerReturnPermission::Cancel, 'Cancel Returns', true),
                $this->item(CustomerReturnPermission::RecordMarketplaceDisposition, 'Record Marketplace Disposition', true),
                $this->item(CustomerReturnPermission::ViewRefundAmount, 'View Refund Amounts', true, true),
                $this->item(CustomerReturnPermission::RecordRefund, 'Record Customer Refunds', true, true),
                $this->item(MarketplaceReturnPermission::View, 'View Marketplace Removals'),
                $this->item(MarketplaceReturnPermission::RequestRemoval, 'Request Marketplace Removal', true),
                $this->item(MarketplaceReturnPermission::DispatchToCompany, 'Dispatch Return to Company', true),
                $this->item(MarketplaceReturnPermission::ReceiveCompany, 'Receive Marketplace Return', true),
            ],
            'Damaged Items' => [
                $this->item(DamagedStockPermission::View, 'View Damaged Items'),
            ],
            'Claims' => [
                $this->item(SafetClaimPermission::View, 'View Claims'),
                $this->item(SafetClaimPermission::File, 'File Claims', true),
                $this->item(SafetClaimPermission::UpdateStatus, 'Update Claim Status', true),
                $this->item(SafetClaimPermission::Close, 'Close Claims', true),
                $this->item(SafetClaimPermission::Assign, 'Assign Claims', true),
                $this->item(SafetClaimPermission::ViewFinancial, 'View Claim Financials', true, true),
                $this->item(SafetClaimPermission::UpdateFinancial, 'Update Claim Financials', true, true),
            ],
            'Service' => [
                $this->item(WarrantyRepairPermission::View, 'View Warranty / Repair'),
                $this->item(WarrantyRepairPermission::Create, 'Create Warranty / Repair'),
                $this->item(WarrantyRepairPermission::UpdateStatus, 'Update Warranty Status', true),
                $this->item(WarrantyRepairPermission::Assign, 'Assign Warranty Cases', true),
                $this->item(WarrantyRepairPermission::Receive, 'Receive Service Items', true),
                $this->item(WarrantyRepairPermission::Inspect, 'Inspect Service Items', true),
                $this->item(WarrantyRepairPermission::MoveToDamaged, 'Move Warranty to Damaged', true),
                $this->item(ComplaintPermission::View, 'View Complaints'),
                $this->item(ComplaintPermission::Create, 'Create Complaints'),
                $this->item(ComplaintPermission::Update, 'Update Complaints', true),
                $this->item(ComplaintPermission::Assign, 'Assign Complaints', true),
                $this->item(ComplaintPermission::Resolve, 'Resolve Complaints', true),
            ],
            'Tasks & Work' => [
                $this->item(TaskPermission::View, 'View Tasks'),
                $this->item(TaskPermission::Create, 'Create Tasks'),
                $this->item(TaskPermission::Update, 'Update Tasks', true),
                $this->item(TaskPermission::Assign, 'Assign Tasks', true),
                $this->item(TaskPermission::ChangeStatus, 'Change Task Status'),
                $this->item(TaskPermission::Complete, 'Complete Tasks'),
                $this->item(TaskPermission::Cancel, 'Cancel Tasks', true),
                $this->item(TaskPermission::ViewTeam, 'View Team Tasks'),
                $this->item(TaskPermission::ManageAll, 'Manage All Tasks', true),
            ],
            'Internal Chat' => [
                $this->item(ChatPermission::View, 'View Chat'),
                $this->item(ChatPermission::Direct, 'Direct Conversations'),
                $this->item(ChatPermission::Team, 'Team Conversations'),
                $this->item(ChatPermission::Context, 'Context Conversations'),
                $this->item(ChatPermission::Manage, 'Manage Chat', true),
                $this->item(ChatPermission::ChannelCreate, 'Create Channels', true),
                $this->item(ChatPermission::ChannelManage, 'Manage Channels', true),
                $this->item(ChatPermission::ChannelArchive, 'Archive Channels', true),
                $this->item(ChatPermission::ChannelJoin, 'Join Public Channels'),
                $this->item(ChatPermission::Thread, 'Reply in Threads'),
                $this->item(ChatPermission::React, 'React to Messages'),
                $this->item(ChatPermission::Pin, 'Pin Messages', true),
                $this->item(ChatPermission::Search, 'Search Chat'),
            ],
        ];
    }

    /** @return array{key: string, label: string, sensitive: bool, financial: bool}|null */
    public function find(string $key): ?array
    {
        foreach ($this->groups() as $permissions) {
            foreach ($permissions as $permission) {
                if ($permission['key'] === $key) {
                    return $permission;
                }
            }
        }

        return null;
    }

    public function roleDefault(Employee $employee, string $key): bool
    {
        $user = $employee->user ?? (new User)->forceFill(['id' => $employee->user_id]);
        $user->setRelation('employee', $employee);

        return match (true) {
            OrderPermission::tryFrom($key) !== null => app(RoleBasedOrderPermissionResolver::class)
                ->roleDefault($user, OrderPermission::from($key)),
            WebSalesPermission::tryFrom($key) !== null => app(WebSalesAuthorization::class)
                ->roleDefault($user, WebSalesPermission::from($key)),
            PurchasePermission::tryFrom($key) !== null => app(RoleBasedPurchasePermissionResolver::class)
                ->roleDefault($user, PurchasePermission::from($key)),
            PeoplePermission::tryFrom($key) !== null => app(RoleBasedPeoplePermissionResolver::class)
                ->roleDefault($user, PeoplePermission::from($key)),
            CatalogPermission::tryFrom($key) !== null => app(RoleBasedCatalogPermissionResolver::class)
                ->roleDefault($user, CatalogPermission::from($key)),
            ProductPermission::tryFrom($key) !== null => app(RoleBasedProductPermissionResolver::class)
                ->roleDefault($user, ProductPermission::from($key)),
            ComponentPermission::tryFrom($key) !== null => app(ComponentAuthorization::class)
                ->roleDefault($user, ComponentPermission::from($key)),
            UpgradePermission::tryFrom($key) !== null => app(UpgradeAuthorization::class)
                ->roleDefault($user, UpgradePermission::from($key)),
            InventoryPermission::tryFrom($key) !== null => app(RoleBasedInventoryPermissionResolver::class)
                ->roleDefault($user, InventoryPermission::from($key)),
            InventoryLocationPermission::tryFrom($key) !== null => app(RoleBasedInventoryLocationPermissionResolver::class)
                ->roleDefault($user, InventoryLocationPermission::from($key)),
            StockTransferPermission::tryFrom($key) !== null => app(RoleBasedStockTransferPermissionResolver::class)
                ->roleDefault($user, StockTransferPermission::from($key)),
            CustomerReturnPermission::tryFrom($key) !== null => app(CustomerReturnAuthorization::class)
                ->roleDefault($user, CustomerReturnPermission::from($key)),
            MarketplaceReturnPermission::tryFrom($key) !== null => app(MarketplaceReturnAuthorization::class)
                ->roleDefault($user, MarketplaceReturnPermission::from($key)),
            DamagedStockPermission::tryFrom($key) !== null => app(DamagedStockAuthorization::class)
                ->roleDefault($user, DamagedStockPermission::from($key)),
            SafetClaimPermission::tryFrom($key) !== null => app(SafetClaimAuthorization::class)
                ->roleDefault($user, SafetClaimPermission::from($key)),
            WarrantyRepairPermission::tryFrom($key) !== null => app(WarrantyRepairAuthorization::class)->roleDefault($user, WarrantyRepairPermission::from($key)),
            ComplaintPermission::tryFrom($key) !== null => app(ComplaintAuthorization::class)->roleDefault($user, ComplaintPermission::from($key)),
            TaskPermission::tryFrom($key) !== null => app(TaskAuthorization::class)->roleDefault($user, TaskPermission::from($key)),
            ChatPermission::tryFrom($key) !== null => app(ChatAuthorization::class)->roleDefault($user, ChatPermission::from($key)),
            HrPermission::tryFrom($key) !== null => app(HrAuthorization::class)->roleDefault($user, HrPermission::from($key)),
            PerformancePermission::tryFrom($key) !== null => app(PerformanceAuthorization::class)->roleDefault($user, PerformancePermission::from($key)),
            EmailSettingsPermission::tryFrom($key) !== null => app(EmailSettingsAuthorization::class)->roleDefault($user, EmailSettingsPermission::from($key)),
            BackupSettingsPermission::tryFrom($key) !== null => app(BackupSettingsAuthorization::class)->roleDefault($user, BackupSettingsPermission::from($key)),
            ExpensePermission::tryFrom($key) !== null => app(ExpenseAuthorization::class)->roleDefault($user, ExpensePermission::from($key)),
            OfficeFinancePermission::tryFrom($key) !== null => app(OfficeFinanceAuthorization::class)->roleDefault($user, OfficeFinancePermission::from($key)),
            NotificationRulePermission::tryFrom($key) !== null => app(NotificationRuleAuthorization::class)->roleDefault($user, NotificationRulePermission::from($key)),
            CompanyProfilePermission::tryFrom($key) !== null => app(CompanyProfileAuthorization::class)->roleDefault($user, CompanyProfilePermission::from($key)),
            AuthSecurityPermission::tryFrom($key) !== null => app(AuthSecurityAuthorization::class)->roleDefault($user, AuthSecurityPermission::from($key)),
            InvoicePermission::tryFrom($key) !== null => app(InvoiceAuthorization::class)->roleDefault($user, InvoicePermission::from($key)),
            QuotationPermission::tryFrom($key) !== null => app(QuotationAuthorization::class)->roleDefault($user, QuotationPermission::from($key)),
            ResponsibilityPermission::tryFrom($key) !== null => app(RoleBasedResponsibilityPermissionResolver::class)
                ->roleDefault($user, ResponsibilityPermission::from($key)),
            default => false,
        };
    }

    private function item(\BackedEnum $permission, string $label, bool $sensitive = false, bool $financial = false): array
    {
        return compact('label', 'sensitive', 'financial') + ['key' => $permission->value];
    }
}
