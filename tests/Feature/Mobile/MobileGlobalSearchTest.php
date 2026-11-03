<?php

namespace Tests\Feature\Mobile;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\ProductPermission;
use App\Enums\PurchasePermission;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceOperationIncident;
use App\Models\MarketplaceOperationIncidentRecipient;
use App\Models\MarketplacePlatform;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductInventory;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarningCategory;
use App\Services\Chat\ConversationService;
use App\Services\Hr\EmployeeWarningService;
use App\Services\Hr\HrNoticeService;
use App\Services\Qc\QcInspectionService;
use App\Services\Responsibilities\ResponsibilityAssignmentService;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use App\Services\Search\GlobalSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class MobileGlobalSearchTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation { responsibilityUser as foundationResponsibilityUser; }

    protected function responsibilityUser(EmployeeRole $role, ?Team $team = null): User
    {
        $user = $this->foundationResponsibilityUser($role, $team);
        $email = 'mobile-search-scope-'.$user->id.'@techpointzone.com';
        $user->forceFill(['email' => $email])->save();
        $user->employee->forceFill(['email' => $email])->save();

        return $user->refresh();
    }

    public function test_authorized_product_order_employee_and_hr_records_return_mobile_targets_without_web_urls(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $staff = $this->employee(EmployeeRole::Staff, [
            'employee_id' => 'TPZ-SEARCH-STAFF',
            'name' => 'Mobile Search Staff',
        ]);
        $product = Product::factory()->create([
            'sku' => 'MOBILE-SEARCH-PRODUCT',
            'name' => 'Build Six Laptop',
        ]);
        $warehouse = Warehouse::factory()->create();
        $order = Order::query()->create([
            'reference' => 'MOBILE-SEARCH-ORDER',
            'source' => 'manual',
            'status' => 'draft',
            'warehouse_id' => $warehouse->id,
            'order_date' => now()->toDateString(),
            'subtotal' => 0,
            'discount_total' => 0,
            'vat_total' => 0,
            'grand_total' => 0,
            'idempotency_key' => (string) Str::uuid(),
            'created_by_user_id' => $owner->id,
        ]);
        $notice = app(HrNoticeService::class)->publish([
            'audience_type' => 'selected',
            'employee_ids' => [$staff->employee->id],
            'title' => 'Mobile Search Notice',
            'content' => 'Visible through authorized global search.',
            'priority' => 'normal',
            'published_at' => now()->toDateTimeString(),
            'acknowledgment_required' => false,
        ], $owner);
        $category = WarningCategory::query()->create([
            'name' => 'Search conduct',
            'status' => true,
            'created_by_user_id' => $owner->id,
        ]);
        $warning = app(EmployeeWarningService::class)->issue([
            'employee_id' => $staff->employee->id,
            'warning_level' => 'verbal',
            'warning_category_id' => $category->id,
            'title' => 'Mobile Search Warning',
            'description' => 'Visible only in authorized HR scope.',
            'issued_date' => now()->toDateString(),
            'acknowledgment_required' => false,
        ], $owner);

        $this->assertTarget($owner, $product->sku, 'Products', ['module' => 'products', 'id' => $product->id]);
        $this->assertTarget($owner, $order->reference, 'Orders', ['module' => 'orders', 'id' => $order->id]);
        $this->assertTarget($owner, 'TPZ-SEARCH-STAFF', 'Employees', ['module' => 'hr/employees', 'id' => $staff->employee->id]);
        $this->assertTarget($owner, 'Mobile Search Notice', 'Notices', ['module' => 'hr/notices', 'id' => $notice->id]);
        $this->assertTarget($owner, 'Mobile Search Warning', 'Warnings', ['module' => 'hr/warnings', 'id' => $warning->id]);
    }

    public function test_official_notices_keep_the_notices_group_and_announcements_remain_separate(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $officialNotice = app(HrNoticeService::class)->publish([
            'audience_type' => 'selected',
            'employee_ids' => [$owner->employee->id],
            'title' => 'Mobile Group Official Notice',
            'content' => 'Official notice search result.',
            'priority' => 'normal',
            'published_at' => now()->toDateTimeString(),
            'acknowledgment_required' => false,
        ], $owner);
        $announcement = app(HrNoticeService::class)->publishAnnouncement([
            'audience_type' => 'selected',
            'employee_ids' => [$owner->employee->id],
            'title' => 'Mobile Group Announcement',
            'content' => 'Announcement search result.',
            'priority' => 'normal',
            'published_at' => now()->toDateTimeString(),
            'acknowledgment_required' => false,
        ], $owner);

        $this->assertTarget($owner, $officialNotice->title, 'Notices', ['module' => 'hr/notices', 'id' => $officialNotice->id]);
        $this->assertTarget($owner, $announcement->title, 'Announcements', ['module' => 'hr/notices', 'id' => $announcement->id]);
    }

    public function test_product_search_supports_normalized_and_legacy_brand_values(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $acer = ProductBrand::factory()->create([
            'name' => 'Acer',
            'normalized_name' => 'acer',
        ]);
        $normalized = Product::factory()->create([
            'sku' => 'BRAND-NORMALIZED-001',
            'name' => 'Travel Notebook',
            'model' => 'NX-100',
            'brand' => 'Historical Catalog Value',
            'brand_id' => $acer->id,
        ]);
        $legacy = Product::factory()->create([
            'sku' => 'BRAND-LEGACY-001',
            'name' => 'Office Notebook',
            'model' => 'LX-200',
            'brand' => 'Legacy Acer',
            'brand_id' => null,
        ]);

        $this->assertTarget($owner, 'acer', 'Products', ['module' => 'products', 'id' => $normalized->id]);
        $this->assertTarget($owner, 'Legacy Acer', 'Products', ['module' => 'products', 'id' => $legacy->id]);
    }

    public function test_staff_brand_search_remains_limited_to_product_responsibility_scope(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $staff = $this->employee(EmployeeRole::Staff);
        $acer = ProductBrand::factory()->create([
            'name' => 'Acer',
            'normalized_name' => 'acer',
        ]);
        $authorized = Product::factory()->create([
            'sku' => 'ACER-AUTHORIZED-001',
            'name' => 'Authorized Notebook',
            'brand' => 'Catalog Brand',
            'brand_id' => $acer->id,
        ]);
        $unrelated = Product::factory()->create([
            'sku' => 'ACER-UNRELATED-001',
            'name' => 'Unrelated Notebook',
            'brand' => 'Catalog Brand',
            'brand_id' => $acer->id,
        ]);

        app(ResponsibilityAssignmentService::class)->create(
            $this->assignmentData(
                ['employee' => $staff->employee, 'brand' => $acer],
                overrides: ['brandId' => null, 'productId' => $authorized->id],
            ),
            $owner,
        );
        EmployeePermissionOverride::query()->create([
            'employee_id' => $staff->employee->id,
            'permission_key' => ProductPermission::View->value,
            'effect' => EmployeePermissionEffect::Allow,
            'granted_by_user_id' => $owner->id,
            'reason' => 'Brand search responsibility-scope test',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($staff->employee->id);

        $scope = app(ResponsibilityProductScopeService::class);
        $this->assertTrue($scope->canAccessProduct($staff, $authorized->id));
        $this->assertFalse($scope->canAccessProduct($staff, $unrelated->id));

        $response = $this->search($staff, 'acer')->assertOk();
        $items = collect(collect($response->json('data'))->firstWhere('group', 'Products')['items'] ?? []);
        $productIds = $items->pluck('target.id');

        $this->assertTrue($productIds->contains($authorized->id), $response->getContent());
        $this->assertFalse($productIds->contains($unrelated->id), $response->getContent());
        $this->assertStringNotContainsString('"url"', $response->getContent());
    }

    public function test_existing_product_sku_name_and_model_searches_continue_to_work(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $product = Product::factory()->create([
            'sku' => 'EXISTING-SEARCH-SKU',
            'name' => 'Existing Search Name',
            'model' => 'EXISTING-MODEL-900',
        ]);
        $target = ['module' => 'products', 'id' => $product->id];

        $this->assertTarget($owner, 'EXISTING-SEARCH-SKU', 'Products', $target);
        $this->assertTarget($owner, 'Existing Search Name', 'Products', $target);
        $this->assertTarget($owner, 'EXISTING-MODEL-900', 'Products', $target);
    }

    public function test_product_search_respects_effective_permission_denial(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $admin = $this->employee(EmployeeRole::Admin);
        $product = Product::factory()->create([
            'sku' => 'PRIVATE-MOBILE-PRODUCT',
            'name' => 'Private Mobile Product',
        ]);
        EmployeePermissionOverride::query()->create([
            'employee_id' => $admin->employee->id,
            'permission_key' => ProductPermission::View->value,
            'effect' => EmployeePermissionEffect::Deny,
            'granted_by_user_id' => $owner->id,
            'reason' => 'Mobile search privacy test',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($admin->employee->id);

        $this->search($admin, $product->sku)->assertOk()->assertJsonPath('data', []);
    }

    public function test_hr_search_does_not_expose_records_outside_recipient_scope(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $recipient = $this->employee(EmployeeRole::Staff);
        $outsider = $this->employee(EmployeeRole::Staff);
        $notice = app(HrNoticeService::class)->publish([
            'audience_type' => 'selected',
            'employee_ids' => [$recipient->employee->id],
            'title' => 'Private Mobile Notice',
            'content' => 'Recipient only.',
            'priority' => 'normal',
            'published_at' => now()->toDateTimeString(),
            'acknowledgment_required' => false,
        ], $owner);
        $category = WarningCategory::query()->create([
            'name' => 'Private category',
            'status' => true,
            'created_by_user_id' => $owner->id,
        ]);
        $warning = app(EmployeeWarningService::class)->issue([
            'employee_id' => $recipient->employee->id,
            'warning_level' => 'verbal',
            'warning_category_id' => $category->id,
            'title' => 'Private Mobile Warning',
            'description' => 'Warned employee only.',
            'issued_date' => now()->toDateString(),
            'acknowledgment_required' => false,
        ], $owner);

        $this->search($outsider, $notice->title)->assertOk()->assertJsonPath('data', []);
        $this->search($outsider, $warning->title)->assertOk()->assertJsonPath('data', []);
    }

    public function test_supplier_results_use_the_new_mobile_destination_without_web_urls(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $supplier = Supplier::factory()->create(['name' => 'Supported Mobile Supplier']);

        $response = $this->search($owner, $supplier->name)->assertOk();
        $item = collect($response->json('data'))->flatMap(fn (array $group) => $group['items'])
            ->firstWhere('target.module', 'suppliers');

        $this->assertSame($supplier->id, $item['target']['id']);
        $this->assertStringNotContainsString('"url"', $response->getContent());
    }

    public function test_authorized_module_pages_are_searchable_without_exposing_denied_modules(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $admin = $this->employee(EmployeeRole::Admin);

        $this->assertTarget($owner, 'purchase receipts', 'Modules & Pages', [
            'module' => 'purchase-receipts',
            'id' => 0,
        ]);
        EmployeePermissionOverride::query()->create([
            'employee_id' => $admin->employee->id,
            'permission_key' => PurchasePermission::ViewReceipts->value,
            'effect' => EmployeePermissionEffect::Deny,
            'granted_by_user_id' => $owner->id,
            'reason' => 'Mobile module search authorization test',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($admin->employee->id);

        $response = $this->search($admin, 'purchase receipts')->assertOk();
        $response->assertJsonMissing(['target' => ['module' => 'purchase-receipts', 'id' => 0]]);
    }

    public function test_inventory_search_keeps_employee_product_responsibility_scope(): void
    {
        $foundation = $this->responsibilityFoundation(5);
        $foundation['product']->forceFill(['name' => 'Scoped Global Inventory Device'])->save();
        app(ResponsibilityAssignmentService::class)->create($this->assignmentData($foundation), $foundation['owner']);
        $unrelatedBrand = ProductBrand::factory()->create(['name' => 'Unrelated Search Brand']);
        $unrelatedProduct = Product::factory()->create([
            'name' => 'Scoped Global Inventory Device',
            'brand' => $unrelatedBrand->name,
            'brand_id' => $unrelatedBrand->id,
        ]);
        $unrelatedInventory = ProductInventory::factory()->create([
            'product_id' => $unrelatedProduct->id,
            'warehouse_id' => $foundation['inventory']->warehouse_id,
        ]);

        $response = $this->search($foundation['employee']->user, 'Scoped Global Inventory')->assertOk();
        $items = collect(collect($response->json('data'))->firstWhere('group', 'Inventory')['items'] ?? []);

        $this->assertTrue($items->pluck('target.id')->contains($foundation['inventory']->id), $response->getContent());
        $this->assertFalse($items->pluck('target.id')->contains($unrelatedInventory->id), $response->getContent());
    }

    public function test_marketplace_incident_search_remains_recipient_private(): void
    {
        $recipient = $this->employee(EmployeeRole::Staff);
        $outsider = $this->employee(EmployeeRole::Staff);
        $platform = MarketplacePlatform::factory()->create();
        $account = MarketplaceAccount::query()->create([
            'marketplace_platform_id' => $platform->id,
            'name' => 'Search Marketplace Account',
            'code' => 'search-marketplace-account',
            'product_condition' => 'new',
            'enabled' => true,
        ]);
        $product = Product::factory()->create(['sku' => 'SEARCH-INCIDENT-SKU']);
        $incident = MarketplaceOperationIncident::query()->create([
            'incident_key' => (string) Str::uuid(),
            'product_id' => $product->id,
            'marketplace_platform_id' => $platform->id,
            'marketplace_account_id' => $account->id,
            'incident_type' => MarketplaceOperationIncident::STOCK_EXPOSURE,
            'active_key' => 'mobile-search:'.$product->id,
            'exposed_listing_count' => 2,
            'usable_quantity' => 0,
            'opened_at' => now(),
        ]);
        MarketplaceOperationIncidentRecipient::query()->create([
            'incident_id' => $incident->id,
            'user_id' => $recipient->id,
            'recipient_role' => MarketplaceOperationIncidentRecipient::PRIMARY,
        ]);

        $this->assertTarget($recipient, $product->sku, 'Marketplace Incidents', [
            'module' => 'marketplace-operations/incidents',
            'id' => $incident->id,
        ]);
        $this->search($outsider, $product->sku)->assertOk()->assertJsonMissing([
            'target' => ['module' => 'marketplace-operations/incidents', 'id' => $incident->id],
        ]);
    }

    public function test_leave_and_chat_search_reuse_record_privacy_scopes(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $staff = $this->employee(EmployeeRole::Staff);
        $outsider = $this->employee(EmployeeRole::Staff);
        $leave = LeaveRequest::query()->create([
            'reference' => 'LR-GLOBAL-SEARCH-PRIVATE',
            'employee_id' => $staff->employee->id,
            'team_id_at_request' => $staff->employee->team_id,
            'leave_type_id' => LeaveType::query()->firstOrFail()->id,
            'leave_policy_id' => LeavePolicy::query()->firstOrFail()->id,
            'from_date' => now()->addWeek()->toDateString(),
            'to_date' => now()->addWeek()->toDateString(),
            'requested_working_days' => '1.00',
            'status' => 'pending',
            'reason' => 'Private global search leave',
            'submitted_at' => now(),
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $conversation = app(ConversationService::class)->direct($owner, $staff->employee);

        $this->assertTarget($staff, $leave->reference, 'Leave Requests', [
            'module' => 'hr/leave-requests',
            'id' => $leave->id,
        ]);
        $this->assertTarget($staff, $owner->employee->name, 'Chat', [
            'module' => 'chat',
            'id' => $conversation->id,
        ]);

        $outsiderLeave = $this->search($outsider, $leave->reference)->assertOk();
        $outsiderLeave->assertJsonMissing(['target' => ['module' => 'hr/leave-requests', 'id' => $leave->id]]);
        $outsiderChat = $this->search($outsider, $owner->employee->name)->assertOk();
        $outsiderChat->assertJsonMissing(['target' => ['module' => 'chat', 'id' => $conversation->id]]);
    }

    public function test_authorized_qc_inspection_search_returns_the_exact_native_destination(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $product = Product::factory()->create();
        $inspection = app(QcInspectionService::class)->start([
            'product_id' => $product->id,
            'warehouse_id' => Warehouse::factory()->create()->id,
            'serial' => 'QC-GLOBAL-SEARCH-001',
            'features' => [],
            'idempotency_key' => (string) Str::uuid(),
        ], $owner);

        $this->assertTarget($owner, 'QC-GLOBAL-SEARCH-001', 'QC Inspections', [
            'module' => 'qc/inspections',
            'id' => $inspection->id,
        ]);
    }

    public function test_qc_focused_user_can_search_without_unrelated_inventory_permission_failure(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $technician = $this->employee(EmployeeRole::Technician);
        $otherTechnician = $this->employee(EmployeeRole::Technician);
        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $service = app(QcInspectionService::class);
        $own = $service->start([
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'serial' => 'QC-FOCUSED-OWN-001', 'features' => [], 'idempotency_key' => (string) Str::uuid(),
        ], $owner);
        $other = $service->start([
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'serial' => 'QC-FOCUSED-OTHER-001', 'features' => [], 'idempotency_key' => (string) Str::uuid(),
        ], $owner);
        $own->forceFill(['technician_user_id' => $technician->id])->save();
        $other->forceFill(['technician_user_id' => $otherTechnician->id])->save();

        $this->assertTarget($technician, 'qc', 'Modules & Pages', [
            'module' => 'qc',
            'id' => 0,
        ]);
        $this->assertTarget($technician, 'QC-FOCUSED-OWN-001', 'QC Inspections', [
            'module' => 'qc/inspections',
            'id' => $own->id,
        ]);
        $this->search($technician, 'QC-FOCUSED-OTHER-001')->assertOk()->assertJsonPath('data', []);
    }

    public function test_short_queries_return_empty_results_safely(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        Product::factory()->create(['name' => 'Short Query Product']);

        $this->search($owner, ' x ')
            ->assertOk()
            ->assertExactJson([
                'data' => [],
                'minimum_query_length' => GlobalSearchService::MIN_QUERY_LENGTH,
            ]);
    }

    public function test_search_requires_authentication(): void
    {
        $this->getJson('/api/mobile/v1/workspace/search?q=product')->assertUnauthorized();
    }

    private function assertTarget(User $user, string $query, string $group, array $target): void
    {
        $response = $this->search($user, $query)->assertOk()
            ->assertJsonPath('minimum_query_length', GlobalSearchService::MIN_QUERY_LENGTH);
        $groups = collect($response->json('data'));
        $items = collect($groups->firstWhere('group', $group)['items'] ?? []);

        $this->assertTrue($items->contains(fn (array $item): bool => $item['target'] === $target));
        $this->assertStringNotContainsString('"url"', $response->getContent());
    }

    private function search(User $user, string $query)
    {
        app('auth')->forgetGuards();

        return $this->withToken($user->createToken('Mobile search test')->plainTextToken)
            ->getJson('/api/mobile/v1/workspace/search?'.http_build_query(['q' => $query]));
    }

    private function employee(EmployeeRole $role, array $attributes = []): User
    {
        $employee = Employee::factory()->role($role)->create($attributes);
        $email = 'mobile-search-'.$employee->id.'@techpointzone.com';
        $employee->forceFill(['email' => $email])->save();
        $employee->user->forceFill(['email' => $email])->save();

        return $employee->user->refresh();
    }
}
