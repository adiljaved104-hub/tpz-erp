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
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarningCategory;
use App\Services\Hr\EmployeeWarningService;
use App\Services\Hr\HrNoticeService;
use App\Services\Search\GlobalSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileGlobalSearchTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_results_without_mobile_destinations_are_omitted(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $supplier = Supplier::factory()->create(['name' => 'Unsupported Mobile Supplier']);

        $this->search($owner, $supplier->name)
            ->assertOk()
            ->assertJsonPath('data', []);
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
