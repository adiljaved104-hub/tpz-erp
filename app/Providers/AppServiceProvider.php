<?php

namespace App\Providers;

use App\Contracts\AttendanceSourceImporter;
use App\Contracts\CatalogPermissionResolver;
use App\Contracts\EmployeePermissionOverrideResolver;
use App\Contracts\InventoryLocationPermissionResolver;
use App\Contracts\InventoryPermissionResolver;
use App\Contracts\OrderPermissionGrantResolver;
use App\Contracts\OrderPermissionResolver;
use App\Contracts\PeoplePermissionResolver;
use App\Contracts\ProductPermissionResolver;
use App\Contracts\ProductQueryInterpreterInterface;
use App\Contracts\PurchasePermissionResolver;
use App\Contracts\ResponsibilityPermissionResolver;
use App\Contracts\StockTransferPermissionResolver;
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
use App\Enums\InventoryLocationPermission;
use App\Enums\InventoryPermission;
use App\Enums\InvoicePermission;
use App\Enums\MarketplaceReturnPermission;
use App\Enums\NotificationRulePermission;
use App\Enums\OrderPermission;
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
use App\Http\Responses\Auth\LoginResponse;
use App\Models\ActivityLog;
use App\Models\AttendancePolicy;
use App\Models\BackupSetting;
use App\Models\CompanyProfile;
use App\Models\CompensatoryOff;
use App\Models\Complaint;
use App\Models\ComplaintStatusEvent;
use App\Models\Component;
use App\Models\ComponentRecoveryValueEvent;
use App\Models\Conversation;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnInspection;
use App\Models\CustomerReturnItem;
use App\Models\CustomerReturnMarketplaceDisposition;
use App\Models\CustomerReturnRefund;
use App\Models\CustomerReturnStatusEvent;
use App\Models\DamagedStockEvent;
use App\Models\EmailSetting;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeLoan;
use App\Models\EmployeeLoanRepayment;
use App\Models\EmployeeWarning;
use App\Models\Expense;
use App\Models\HrNotice;
use App\Models\InventoryReservation;
use App\Models\InvoiceSetting;
use App\Models\LeaveRequest;
use App\Models\LoginSecuritySetting;
use App\Models\MarketplacePlatform;
use App\Models\MarketplaceReturnRemoval;
use App\Models\MarketplaceReturnRemovalEvent;
use App\Models\MarketplaceReturnRemovalItem;
use App\Models\NoticeCategory;
use App\Models\NoticeTemplate;
use App\Models\NotificationRule;
use App\Models\OfficeFinanceAccount;
use App\Models\OfficeFinanceTransaction;
use App\Models\OpeningStockEntry;
use App\Models\Order;
use App\Models\OrderFulfillment;
use App\Models\OrderFulfillmentItem;
use App\Models\OrderItem;
use App\Models\OrderItemUpgradeSelection;
use App\Models\OrderStatusEvent;
use App\Models\OrderUpgradeExecution;
use App\Models\OrderUpgradeExecutionLine;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductHardwareProfile;
use App\Models\ProductInventory;
use App\Models\PublicHoliday;
use App\Models\Purchase;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use App\Models\Quotation;
use App\Models\QuotationEmailDelivery;
use App\Models\QuotationItem;
use App\Models\ResponsibilityAssignment;
use App\Models\SafetClaim;
use App\Models\SafetClaimStatusEvent;
use App\Models\SalesConfiguration;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\StockTransferStatusEvent;
use App\Models\Supplier;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\TaxInvoice;
use App\Models\Team;
use App\Models\UpgradeRecipe;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarningCategory;
use App\Models\WarrantyRepair;
use App\Models\WarrantyRepairStatusEvent;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleAssignment;
use App\Observers\CustomerReturnAlertObserver;
use App\Observers\InventoryReservationObserver;
use App\Observers\MarketplacePlatformObserver;
use App\Observers\OpeningStockEntryObserver;
use App\Observers\ProductBrandObserver;
use App\Observers\ProductCategoryObserver;
use App\Observers\ProductInventoryObserver;
use App\Observers\ProductObserver;
use App\Observers\PurchaseObserver;
use App\Observers\PurchaseReceiptItemObserver;
use App\Observers\PurchaseReceiptObserver;
use App\Observers\ResponsibilityAssignmentObserver;
use App\Observers\SafetClaimAlertObserver;
use App\Observers\StockMovementObserver;
use App\Observers\SupplierObserver;
use App\Observers\WarehouseObserver;
use App\Policies\ActivityLogPolicy;
use App\Policies\ComponentPolicy;
use App\Policies\ConversationPolicy;
use App\Policies\CustomerReturnPolicy;
use App\Policies\EmployeePolicy;
use App\Policies\FinancialDataPolicy;
use App\Policies\InventoryReservationPolicy;
use App\Policies\MarketplacePlatformPolicy;
use App\Policies\MarketplaceReturnRemovalPolicy;
use App\Policies\OpeningStockEntryPolicy;
use App\Policies\OrderPolicy;
use App\Policies\ProductBrandPolicy;
use App\Policies\ProductCategoryPolicy;
use App\Policies\ProductInventoryPolicy;
use App\Policies\ProductPolicy;
use App\Policies\PurchasePolicy;
use App\Policies\PurchaseReceiptPolicy;
use App\Policies\ResponsibilityAssignmentPolicy;
use App\Policies\StockMovementPolicy;
use App\Policies\StockTransferPolicy;
use App\Policies\SupplierPolicy;
use App\Policies\TaskPolicy;
use App\Policies\TeamPolicy;
use App\Policies\WarehousePolicy;
use App\Services\Authorization\AuthSecurityAuthorization;
use App\Services\Authorization\BackupSettingsAuthorization;
use App\Services\Authorization\CatalogAuthorization;
use App\Services\Authorization\ChatAuthorization;
use App\Services\Authorization\CompanyProfileAuthorization;
use App\Services\Authorization\ComplaintAuthorization;
use App\Services\Authorization\ComponentAuthorization;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Authorization\DamagedStockAuthorization;
use App\Services\Authorization\DatabaseEmployeePermissionOverrideResolver;
use App\Services\Authorization\DatabaseOrderPermissionGrantResolver;
use App\Services\Authorization\EmailSettingsAuthorization;
use App\Services\Authorization\ExpenseAuthorization;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\InventoryLocationAuthorization;
use App\Services\Authorization\InvoiceAuthorization;
use App\Services\Authorization\MarketplaceReturnAuthorization;
use App\Services\Authorization\NotificationRuleAuthorization;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\PerformanceAuthorization;
use App\Services\Authorization\ProductAuthorization;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\Authorization\QuotationAuthorization;
use App\Services\Authorization\ResponsibilityAuthorization;
use App\Services\Authorization\RoleBasedCatalogPermissionResolver;
use App\Services\Authorization\RoleBasedInventoryLocationPermissionResolver;
use App\Services\Authorization\RoleBasedInventoryPermissionResolver;
use App\Services\Authorization\RoleBasedOrderPermissionResolver;
use App\Services\Authorization\RoleBasedPeoplePermissionResolver;
use App\Services\Authorization\RoleBasedProductPermissionResolver;
use App\Services\Authorization\RoleBasedPurchasePermissionResolver;
use App\Services\Authorization\RoleBasedResponsibilityPermissionResolver;
use App\Services\Authorization\RoleBasedStockTransferPermissionResolver;
use App\Services\Authorization\SafetClaimAuthorization;
use App\Services\Authorization\StockTransferAuthorization;
use App\Services\Authorization\TaskAuthorization;
use App\Services\Authorization\UpgradeAuthorization;
use App\Services\Authorization\WarrantyRepairAuthorization;
use App\Services\Authorization\WebSalesAuthorization;
use App\Services\Hikvision\HikvisionAttendanceImporter;
use App\Services\ProductIntelligence\LocalProductQueryInterpreter;
use App\Services\Reports\Providers\CoreReportProvider;
use App\Services\Reports\Providers\ExpenseReportProvider;
use App\Services\Reports\Providers\OfficeFinanceReportProvider;
use App\Services\Reports\Providers\QuotationReportProvider;
use App\Services\Reports\Providers\WebSalesReportProvider;
use App\Services\Reports\ReportRegistry;
use App\Services\ServiceCases\ServiceCaseAssigneeService;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LoginResponseContract::class, LoginResponse::class);
        $this->app->bind(AttendanceSourceImporter::class, HikvisionAttendanceImporter::class);
        $this->app->singleton(EmployeePermissionOverrideResolver::class, DatabaseEmployeePermissionOverrideResolver::class);
        $this->app->bind(ProductPermissionResolver::class, RoleBasedProductPermissionResolver::class);
        $this->app->bind(ProductQueryInterpreterInterface::class, LocalProductQueryInterpreter::class);
        $this->app->bind(CatalogPermissionResolver::class, RoleBasedCatalogPermissionResolver::class);
        $this->app->bind(InventoryPermissionResolver::class, RoleBasedInventoryPermissionResolver::class);
        $this->app->bind(InventoryLocationPermissionResolver::class, RoleBasedInventoryLocationPermissionResolver::class);
        $this->app->bind(OrderPermissionGrantResolver::class, DatabaseOrderPermissionGrantResolver::class);
        $this->app->bind(OrderPermissionResolver::class, RoleBasedOrderPermissionResolver::class);
        $this->app->bind(PeoplePermissionResolver::class, RoleBasedPeoplePermissionResolver::class);
        $this->app->bind(PurchasePermissionResolver::class, RoleBasedPurchasePermissionResolver::class);
        $this->app->bind(ResponsibilityPermissionResolver::class, RoleBasedResponsibilityPermissionResolver::class);
        $this->app->bind(StockTransferPermissionResolver::class, RoleBasedStockTransferPermissionResolver::class);
        $this->app->tag([
            CoreReportProvider::class,
            WebSalesReportProvider::class,
            ExpenseReportProvider::class,
            OfficeFinanceReportProvider::class,
            QuotationReportProvider::class,
        ], 'reports.providers');
        $this->app->singleton(
            ReportRegistry::class,
            fn ($app): ReportRegistry => new ReportRegistry($app->tagged('reports.providers')),
        );
        $this->app->scoped(ServiceCaseAssigneeService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Employee::class, EmployeePolicy::class);
        Gate::policy(ActivityLog::class, ActivityLogPolicy::class);
        Gate::policy(Supplier::class, SupplierPolicy::class);
        Gate::policy(Team::class, TeamPolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(Conversation::class, ConversationPolicy::class);
        Gate::policy(Warehouse::class, WarehousePolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Component::class, ComponentPolicy::class);
        Gate::policy(ProductBrand::class, ProductBrandPolicy::class);
        Gate::policy(ProductCategory::class, ProductCategoryPolicy::class);
        Gate::policy(ProductInventory::class, ProductInventoryPolicy::class);
        Gate::policy(StockMovement::class, StockMovementPolicy::class);
        Gate::policy(OpeningStockEntry::class, OpeningStockEntryPolicy::class);
        Gate::policy(InventoryReservation::class, InventoryReservationPolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(Purchase::class, PurchasePolicy::class);
        Gate::policy(PurchaseReceipt::class, PurchaseReceiptPolicy::class);
        Gate::policy(MarketplacePlatform::class, MarketplacePlatformPolicy::class);
        Gate::policy(ResponsibilityAssignment::class, ResponsibilityAssignmentPolicy::class);
        Gate::policy(StockTransfer::class, StockTransferPolicy::class);
        Gate::policy(CustomerReturn::class, CustomerReturnPolicy::class);
        Gate::policy(MarketplaceReturnRemoval::class, MarketplaceReturnRemovalPolicy::class);
        Gate::define('viewFinancialData', fn (User $user): bool => app(FinancialDataPolicy::class)->view($user));

        foreach (ProductPermission::cases() as $permission) {
            Gate::define(
                $permission->value,
                fn (User $user, ?Product $product = null): bool => app(ProductAuthorization::class)
                    ->allows($user, $permission, $product),
            );
        }

        foreach (ComponentPermission::cases() as $permission) {
            Gate::define(
                $permission->value,
                fn (User $user, ?Component $component = null): bool => app(ComponentAuthorization::class)
                    ->allows($user, $permission, $component),
            );
        }

        foreach (UpgradePermission::cases() as $permission) {
            Gate::define(
                $permission->value,
                fn (User $user): bool => app(UpgradeAuthorization::class)->allows($user, $permission),
            );
        }

        foreach (CatalogPermission::cases() as $permission) {
            Gate::define(
                $permission->value,
                fn (User $user): bool => app(CatalogAuthorization::class)->allows($user, $permission),
            );
        }

        foreach (ChatPermission::cases() as $permission) {
            Gate::define(
                $permission->value,
                fn (User $user): bool => app(ChatAuthorization::class)->allows($user, $permission),
            );
        }

        foreach (InventoryPermission::cases() as $permission) {
            Gate::define(
                $permission->value,
                fn (User $user, ?ProductInventory $inventory = null): bool => app(InventoryAuthorization::class)
                    ->allows($user, $permission, $inventory),
            );
        }

        foreach (InventoryLocationPermission::cases() as $permission) {
            Gate::define(
                $permission->value,
                fn (User $user): bool => app(InventoryLocationAuthorization::class)->allows($user, $permission),
            );
        }

        foreach (OrderPermission::cases() as $permission) {
            Gate::define(
                $permission->value,
                fn (User $user, ?Order $order = null): bool => app(OrderAuthorization::class)
                    ->allows($user, $permission, $order),
            );
        }

        foreach (WebSalesPermission::cases() as $permission) {
            Gate::define(
                $permission->value,
                fn (User $user, ?Order $order = null): bool => app(WebSalesAuthorization::class)
                    ->allows($user, $permission, $order),
            );
        }

        foreach (PurchasePermission::cases() as $permission) {
            Gate::define(
                $permission->value,
                fn (User $user, ?Purchase $purchase = null): bool => app(PurchaseAuthorization::class)
                    ->allows($user, $permission, $purchase),
            );
        }

        foreach (ResponsibilityPermission::cases() as $permission) {
            Gate::define(
                $permission->value,
                fn (User $user, ?ResponsibilityAssignment $assignment = null): bool => app(ResponsibilityAuthorization::class)
                    ->allows($user, $permission, $assignment),
            );
        }

        foreach (StockTransferPermission::cases() as $permission) {
            Gate::define(
                $permission->value,
                fn (User $user, ?StockTransfer $transfer = null): bool => app(StockTransferAuthorization::class)
                    ->allows($user, $permission, $transfer),
            );
        }

        foreach (CustomerReturnPermission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user, ?CustomerReturn $return = null): bool => app(CustomerReturnAuthorization::class)->allows($user, $permission, $return));
        }
        foreach (MarketplaceReturnPermission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user, ?MarketplaceReturnRemoval $removal = null): bool => app(MarketplaceReturnAuthorization::class)->allows($user, $permission, $removal));
        }
        foreach (DamagedStockPermission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user): bool => app(DamagedStockAuthorization::class)->allows($user, $permission));
        }
        foreach (SafetClaimPermission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user, ?SafetClaim $claim = null): bool => app(SafetClaimAuthorization::class)->allows($user, $permission, $claim));
        }
        foreach (WarrantyRepairPermission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user, ?WarrantyRepair $case = null): bool => app(WarrantyRepairAuthorization::class)->allows($user, $permission, $case));
        }
        foreach (ComplaintPermission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user, ?Complaint $case = null): bool => app(ComplaintAuthorization::class)->allows($user, $permission, $case));
        }
        foreach (TaskPermission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user, ?Task $task = null): bool => app(TaskAuthorization::class)->allows($user, $permission, $task));
        }
        foreach (PerformancePermission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user): bool => app(PerformanceAuthorization::class)->allows($user, $permission));
        }
        foreach (EmailSettingsPermission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user): bool => app(EmailSettingsAuthorization::class)->allows($user, $permission));
        }
        foreach (BackupSettingsPermission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user): bool => app(BackupSettingsAuthorization::class)->allows($user, $permission));
        }
        foreach (ExpensePermission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user): bool => app(ExpenseAuthorization::class)->allows($user, $permission));
        }
        foreach (NotificationRulePermission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user): bool => app(NotificationRuleAuthorization::class)->allows($user, $permission));
        }
        foreach (AuthSecurityPermission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user): bool => app(AuthSecurityAuthorization::class)->allows($user, $permission));
        }
        foreach (CompanyProfilePermission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user): bool => app(CompanyProfileAuthorization::class)->allows($user, $permission));
        }
        foreach (InvoicePermission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user, ?TaxInvoice $invoice = null): bool => app(InvoiceAuthorization::class)->allows($user, $permission, $invoice));
        }
        foreach (QuotationPermission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user, ?Quotation $quotation = null): bool => app(QuotationAuthorization::class)->allows($user, $permission, $quotation));
        }

        Supplier::observe(SupplierObserver::class);
        Warehouse::observe(WarehouseObserver::class);
        Product::observe(ProductObserver::class);
        ProductBrand::observe(ProductBrandObserver::class);
        ProductCategory::observe(ProductCategoryObserver::class);
        ProductInventory::observe(ProductInventoryObserver::class);
        StockMovement::observe(StockMovementObserver::class);
        OpeningStockEntry::observe(OpeningStockEntryObserver::class);
        InventoryReservation::observe(InventoryReservationObserver::class);
        Purchase::observe(PurchaseObserver::class);
        PurchaseReceipt::observe(PurchaseReceiptObserver::class);
        PurchaseReceiptItem::observe(PurchaseReceiptItemObserver::class);
        MarketplacePlatform::observe(MarketplacePlatformObserver::class);
        ResponsibilityAssignment::observe(ResponsibilityAssignmentObserver::class);
        SafetClaim::observe(SafetClaimAlertObserver::class);
        CustomerReturn::observe(CustomerReturnAlertObserver::class);

        Relation::enforceMorphMap([
            'user' => User::class,
            'employee' => Employee::class,
            'supplier' => Supplier::class,
            'warehouse' => Warehouse::class,
            'product' => Product::class,
            'component' => Component::class,
            'component_recovery_value_event' => ComponentRecoveryValueEvent::class,
            'product_hardware_profile' => ProductHardwareProfile::class,
            'sales_configuration' => SalesConfiguration::class,
            'upgrade_recipe' => UpgradeRecipe::class,
            'product_brand' => ProductBrand::class,
            'product_category' => ProductCategory::class,
            'product_inventory' => ProductInventory::class,
            'stock_movement' => StockMovement::class,
            'opening_stock' => OpeningStockEntry::class,
            'inventory_reservation' => InventoryReservation::class,
            'order' => Order::class,
            'order_fulfillment' => OrderFulfillment::class,
            'order_fulfillment_item' => OrderFulfillmentItem::class,
            'order_item' => OrderItem::class,
            'order_item_upgrade_selection' => OrderItemUpgradeSelection::class,
            'order_upgrade_execution' => OrderUpgradeExecution::class,
            'order_upgrade_execution_line' => OrderUpgradeExecutionLine::class,
            'order_status_event' => OrderStatusEvent::class,
            'purchase' => Purchase::class,
            'purchase_receipt' => PurchaseReceipt::class,
            'purchase_receipt_item' => PurchaseReceiptItem::class,
            'marketplace_platform' => MarketplacePlatform::class,
            'responsibility_assignment' => ResponsibilityAssignment::class,
            'stock_transfer' => StockTransfer::class,
            'stock_transfer_item' => StockTransferItem::class,
            'stock_transfer_status_event' => StockTransferStatusEvent::class,
            'customer_return' => CustomerReturn::class,
            'customer_return_item' => CustomerReturnItem::class,
            'customer_return_inspection' => CustomerReturnInspection::class,
            'customer_return_status_event' => CustomerReturnStatusEvent::class,
            'customer_return_marketplace_disposition' => CustomerReturnMarketplaceDisposition::class,
            'customer_return_refund' => CustomerReturnRefund::class,
            'marketplace_return_removal' => MarketplaceReturnRemoval::class,
            'marketplace_return_removal_item' => MarketplaceReturnRemovalItem::class,
            'marketplace_return_removal_event' => MarketplaceReturnRemovalEvent::class,
            'damaged_stock_event' => DamagedStockEvent::class,
            'safet_claim' => SafetClaim::class,
            'safet_claim_status_event' => SafetClaimStatusEvent::class,
            'warranty_repair' => WarrantyRepair::class,
            'warranty_repair_status_event' => WarrantyRepairStatusEvent::class,
            'complaint' => Complaint::class,
            'complaint_status_event' => ComplaintStatusEvent::class,
            'task' => Task::class,
            'task_event' => TaskEvent::class,
            'conversation' => Conversation::class,
            'attendance_policy' => AttendancePolicy::class,
            'employee_attendance' => EmployeeAttendance::class,
            'leave_request' => LeaveRequest::class,
            'work_schedule' => WorkSchedule::class,
            'work_schedule_assignment' => WorkScheduleAssignment::class,
            'public_holiday' => PublicHoliday::class,
            'compensatory_off' => CompensatoryOff::class,
            'warning_category' => WarningCategory::class,
            'employee_warning' => EmployeeWarning::class,
            'hr_notice' => HrNotice::class,
            'notice_category' => NoticeCategory::class,
            'notice_template' => NoticeTemplate::class,
            'email_setting' => EmailSetting::class,
            'notification_rule' => NotificationRule::class,
            'login_security_setting' => LoginSecuritySetting::class,
            'company_profile' => CompanyProfile::class,
            'invoice_setting' => InvoiceSetting::class,
            'tax_invoice' => TaxInvoice::class,
            'quotation' => Quotation::class,
            'quotation_item' => QuotationItem::class,
            'quotation_email_delivery' => QuotationEmailDelivery::class,
            'expense' => Expense::class,
            'office_finance_account' => OfficeFinanceAccount::class,
            'office_finance_transaction' => OfficeFinanceTransaction::class,
            'employee_loan' => EmployeeLoan::class,
            'employee_loan_repayment' => EmployeeLoanRepayment::class,
            'backup_setting' => BackupSetting::class,
        ]);
    }
}
