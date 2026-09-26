<?php

namespace Tests\Feature\Mobile;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\ProductPermission;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarningCategory;
use App\Services\Hr\EmployeeWarningService;
use App\Services\Hr\HrNoticeService;
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
    use ResponsibilityTestFoundation;

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
