<?php

namespace Tests\Feature\ServiceCases;

use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\DTOs\Responsibilities\CreateResponsibilityAssignmentData;
use App\Enums\ComplaintCategory;
use App\Enums\ComplaintPermission;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\ResponsibilityAssignmentMode;
use App\Enums\WarrantyRepairPermission;
use App\Enums\WarrantyRepairSource;
use App\Filament\Resources\Complaints\ComplaintResource;
use App\Filament\Resources\Complaints\Pages\ListComplaints;
use App\Filament\Resources\WarrantyRepairs\Pages\ListWarrantyRepairs;
use App\Filament\Resources\WarrantyRepairs\WarrantyRepairResource;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\MarketplacePlatform;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductInventory;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\ComplaintAuthorization;
use App\Services\Authorization\WarrantyRepairAuthorization;
use App\Services\ServiceCases\ComplaintService;
use App\Services\ServiceCases\ServiceCaseAssigneeService;
use App\Services\ServiceCases\ServiceCaseOrderContextService;
use App\Services\ServiceCases\WarrantyRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServiceCaseResponsibilityOwnershipTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('supportedScopes')]
    public function test_supported_responsibility_scopes_drive_case_ownership(string $scope): void
    {
        [$owner, $staff, $brand, $product, $warehouse, $platform, $inventory] = $this->foundation();
        $this->grantCaseAccess($staff, $owner);

        if ($scope === 'quantity') {
            $this->assign($owner, $staff, inventoryId: $inventory->id);
        } else {
            $this->assign(
                $owner,
                $staff,
                brandId: in_array($scope, ['brand', 'brand_platform'], true) ? $brand->id : null,
                platformId: in_array($scope, ['platform', 'brand_platform', 'product_platform'], true) ? $platform->id : null,
                productId: in_array($scope, ['product', 'product_platform'], true) ? $product->id : null,
            );
        }

        $order = $this->order($owner, $product, $warehouse, $platform);
        $case = app(WarrantyRepairService::class)->create($this->warrantyData($product, $warehouse, $platform, $order), $owner);

        $this->assertSame($staff->id, $case->assigned_to_user_id);
    }

    public static function supportedScopes(): array
    {
        return [
            'Brand' => ['brand'],
            'Platform' => ['platform'],
            'Brand + Platform' => ['brand_platform'],
            'Product' => ['product'],
            'Product + Platform' => ['product_platform'],
            'Quantity / ProductInventory' => ['quantity'],
        ];
    }

    public function test_one_matching_active_employee_is_assigned_to_warranty_and_complaint(): void
    {
        [$owner, $staff, $brand, $product, $warehouse, $platform] = $this->foundation();
        $this->grantCaseAccess($staff, $owner);
        $this->assign($owner, $staff, brandId: $brand->id, platformId: $platform->id);
        $order = $this->order($owner, $product, $warehouse, $platform);

        $warranty = app(WarrantyRepairService::class)->create($this->warrantyData($product, $warehouse, $platform, $order), $owner);
        $complaint = app(ComplaintService::class)->create($this->complaintData($product, $platform, $order), $owner);

        $this->assertSame($staff->id, $warranty->assigned_to_user_id);
        $this->assertSame($staff->id, $complaint->assigned_to_user_id);
    }

    public function test_multiple_matches_leave_cases_unassigned_and_inactive_employees_never_match(): void
    {
        [$owner, $first, $brand, $product, $warehouse, $platform] = $this->foundation();
        $second = $this->employeeUser(EmployeeRole::Staff);
        foreach ([$first, $second] as $user) {
            $this->grantCaseAccess($user, $owner);
            $this->assign($owner, $user, brandId: $brand->id, platformId: $platform->id);
        }
        $order = $this->order($owner, $product, $warehouse, $platform);

        $warranty = app(WarrantyRepairService::class)->create($this->warrantyData($product, $warehouse, $platform, $order), $owner);
        $complaint = app(ComplaintService::class)->create($this->complaintData($product, $platform, $order), $owner);

        $this->assertNull($warranty->assigned_to_user_id);
        $this->assertNull($complaint->assigned_to_user_id);
        $this->assertTrue(app(WarrantyRepairAuthorization::class)->allows($first, WarrantyRepairPermission::View, $warranty));
        $this->assertTrue(app(WarrantyRepairAuthorization::class)->allows($second, WarrantyRepairPermission::View, $warranty));
        $assignees = app(ServiceCaseAssigneeService::class);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], array_keys($assignees->warrantyOptions($warranty)));
        $this->assertSame('Unassigned — multiple eligible employees', $assignees->warrantyPlaceholder($warranty));

        $first->employee->forceFill(['status' => false])->save();
        $second->employee->forceFill(['status' => false])->save();
        $unassigned = app(WarrantyRepairService::class)->create($this->warrantyData($product, $warehouse, $platform, $order), $owner);

        $this->assertNull($unassigned->assigned_to_user_id);
        $this->assertSame([], $assignees->warrantyOptions($unassigned));
        $this->assertSame('No eligible employee', $assignees->warrantyPlaceholder($unassigned));
    }

    public function test_case_visibility_uses_active_responsibility_and_assignment_never_expands_access(): void
    {
        [$owner, $staff, $brand, $product, $warehouse, $platform] = $this->foundation();
        $otherBrand = ProductBrand::factory()->create();
        $otherProduct = Product::factory()->create(['brand_id' => $otherBrand->id, 'brand' => $otherBrand->name]);
        $this->grantCaseAccess($staff, $owner);
        $this->assign($owner, $staff, brandId: $brand->id, platformId: $platform->id);
        // An overlapping Product scope must not duplicate the same case row.
        $this->assign($owner, $staff, productId: $product->id, platformId: $platform->id);
        $matchingOrder = $this->order($owner, $product, $warehouse, $platform, 'SO-2026-880001');
        $otherOrder = $this->order($owner, $otherProduct, $warehouse, $platform, 'SO-2026-880002');
        $matchingWarranty = app(WarrantyRepairService::class)->create($this->warrantyData($product, $warehouse, $platform, $matchingOrder), $owner);
        $otherWarranty = app(WarrantyRepairService::class)->create($this->warrantyData($otherProduct, $warehouse, $platform, $otherOrder), $owner);
        $otherWarranty->forceFill(['assigned_to_user_id' => $staff->id])->save();
        $matchingComplaint = app(ComplaintService::class)->create($this->complaintData($product, $platform, $matchingOrder), $owner);
        $otherComplaint = app(ComplaintService::class)->create($this->complaintData($otherProduct, $platform, $otherOrder), $owner);
        $otherComplaint->forceFill(['assigned_to_user_id' => $staff->id])->save();
        $this->actingAs($staff);

        $this->assertSame([$matchingWarranty->id], WarrantyRepairResource::getEloquentQuery()->pluck('id')->all());
        $this->assertSame([$matchingComplaint->id], ComplaintResource::getEloquentQuery()->pluck('id')->all());
        $this->assertFalse(app(WarrantyRepairAuthorization::class)->allows($staff, WarrantyRepairPermission::View, $otherWarranty->refresh()));
        $this->assertFalse(app(ComplaintAuthorization::class)->allows($staff, ComplaintPermission::View, $otherComplaint->refresh()));
    }

    public function test_scoped_case_creation_and_selectors_reject_unrelated_orders_and_products(): void
    {
        [$owner, $staff, $brand, $product, $warehouse, $platform] = $this->foundation();
        $otherBrand = ProductBrand::factory()->create();
        $otherProduct = Product::factory()->create(['brand_id' => $otherBrand->id, 'brand' => $otherBrand->name]);
        $matchingOrder = $this->order($owner, $product, $warehouse, $platform, 'SO-2026-881001');
        $otherOrder = $this->order($owner, $otherProduct, $warehouse, $platform, 'SO-2026-881002');
        $this->grantCaseAccess($staff, $owner);
        foreach ([WarrantyRepairPermission::Create->value, ComplaintPermission::Create->value] as $permission) {
            EmployeePermissionOverride::query()->create(['employee_id' => $staff->employee->id, 'permission_key' => $permission, 'effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $owner->id, 'reason' => 'Scoped creation test']);
        }
        $this->assign($owner, $staff, brandId: $brand->id, platformId: $platform->id);

        $context = app(ServiceCaseOrderContextService::class);
        $this->assertArrayHasKey($matchingOrder->id, $context->searchOrders('SO-2026-881', $staff));
        $this->assertArrayNotHasKey($otherOrder->id, $context->searchOrders('SO-2026-881', $staff));
        $this->assertSame([$product->id], array_keys($context->productOptions($platform->id, $warehouse->id, $staff)));

        try {
            app(WarrantyRepairService::class)->create($this->warrantyData($otherProduct, $warehouse, $platform, $otherOrder), $staff);
            $this->fail('An unrelated Warranty Product must be rejected server-side.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('warranty_repairs', ['product_id' => $otherProduct->id]);
        }

        try {
            app(ComplaintService::class)->create($this->complaintData($otherProduct, $platform, $otherOrder), $staff);
            $this->fail('An unrelated Complaint Product must be rejected server-side.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('complaints', ['product_id' => $otherProduct->id]);
        }
    }

    public function test_operational_lists_offer_assigned_to_me_and_unassigned_filters(): void
    {
        [$owner, , , $product, $warehouse, $platform] = $this->foundation();
        $order = $this->order($owner, $product, $warehouse, $platform);
        $assignedWarranty = app(WarrantyRepairService::class)->create($this->warrantyData($product, $warehouse, $platform, $order), $owner);
        $assignedWarranty->forceFill(['assigned_to_user_id' => $owner->id])->save();
        $unassignedWarranty = app(WarrantyRepairService::class)->create($this->warrantyData($product, $warehouse, $platform, $order), $owner);
        $assignedComplaint = app(ComplaintService::class)->create($this->complaintData($product, $platform, $order), $owner);
        $assignedComplaint->forceFill(['assigned_to_user_id' => $owner->id])->save();
        $unassignedComplaint = app(ComplaintService::class)->create($this->complaintData($product, $platform, $order), $owner);
        $this->actingAs($owner);

        Livewire::test(ListWarrantyRepairs::class)
            ->assertTableFilterExists('ownership')
            ->filterTable('ownership', 'assigned_to_me')
            ->assertCanSeeTableRecords([$assignedWarranty])
            ->assertCanNotSeeTableRecords([$unassignedWarranty]);
        Livewire::test(ListWarrantyRepairs::class)
            ->filterTable('ownership', 'unassigned')
            ->assertCanSeeTableRecords([$unassignedWarranty])
            ->assertCanNotSeeTableRecords([$assignedWarranty]);
        Livewire::test(ListComplaints::class)
            ->assertTableFilterExists('ownership')
            ->filterTable('ownership', 'assigned_to_me')
            ->assertCanSeeTableRecords([$assignedComplaint])
            ->assertCanNotSeeTableRecords([$unassignedComplaint]);
        Livewire::test(ListComplaints::class)
            ->filterTable('ownership', 'unassigned')
            ->assertCanSeeTableRecords([$unassignedComplaint])
            ->assertCanNotSeeTableRecords([$assignedComplaint]);
    }

    private function foundation(): array
    {
        $owner = $this->employeeUser(EmployeeRole::Owner);
        $staff = $this->employeeUser(EmployeeRole::Staff);
        $brand = ProductBrand::factory()->create();
        $product = Product::factory()->create(['brand_id' => $brand->id, 'brand' => $brand->name]);
        $warehouse = Warehouse::factory()->create(['status' => true]);
        $platform = MarketplacePlatform::factory()->create();
        $inventory = ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 5, 'reserved_quantity' => 0]);

        return [$owner, $staff, $brand, $product, $warehouse, $platform, $inventory];
    }

    private function employeeUser(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'status' => true]);

        return $user->refresh();
    }

    private function grantCaseAccess(User $user, User $actor): void
    {
        foreach ([WarrantyRepairPermission::View->value, WarrantyRepairPermission::UpdateStatus->value, ComplaintPermission::View->value, ComplaintPermission::Update->value] as $permission) {
            EmployeePermissionOverride::query()->create(['employee_id' => $user->employee->id, 'permission_key' => $permission, 'effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $actor->id, 'reason' => 'Case responsibility test']);
        }
    }

    private function assign(User $owner, User $user, ?int $brandId = null, ?int $platformId = null, ?int $productId = null, ?int $inventoryId = null): void
    {
        app(CreateResponsibilityAssignment::class)->handle(new CreateResponsibilityAssignmentData(
            employeeId: $user->employee->id,
            mode: $inventoryId === null ? ResponsibilityAssignmentMode::Scope : ResponsibilityAssignmentMode::Quantity,
            brandId: $brandId,
            platformId: $platformId,
            productId: $productId,
            productInventoryId: $inventoryId,
            assignedQuantity: $inventoryId === null ? null : 1,
            effectiveAt: now()->subMinute()->toDateTimeString(),
            reason: 'Service case ownership',
            notes: null,
            idempotencyKey: (string) Str::uuid(),
        ), $owner);
    }

    private function order(User $owner, Product $product, Warehouse $warehouse, MarketplacePlatform $platform, string $reference = 'SO-2026-880000'): Order
    {
        $order = Order::query()->create(['reference' => $reference, 'source' => 'marketplace', 'status' => 'fulfilled', 'warehouse_id' => $warehouse->id, 'marketplace_platform_id' => $platform->id, 'order_date' => now()->toDateString(), 'subtotal' => 0, 'discount_total' => 0, 'vat_total' => 0, 'grand_total' => 0, 'idempotency_key' => (string) Str::uuid(), 'created_by_user_id' => $owner->id]);
        OrderItem::query()->create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name, 'sku' => $product->sku, 'ordered_quantity' => 1, 'selling_price' => 0, 'discount_total' => 0, 'vat_rate' => 0, 'vat_amount' => 0, 'line_total' => 0]);

        return $order;
    }

    private function warrantyData(Product $product, Warehouse $warehouse, MarketplacePlatform $platform, Order $order): array
    {
        return ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'marketplace_platform_id' => $platform->id, 'order_id' => $order->id, 'quantity' => 1, 'source' => WarrantyRepairSource::Order->value, 'issue_description' => 'Responsibility-owned warranty case', 'received_at' => now(), 'idempotency_key' => (string) Str::uuid()];
    }

    private function complaintData(Product $product, MarketplacePlatform $platform, Order $order): array
    {
        return ['product_id' => $product->id, 'marketplace_platform_id' => $platform->id, 'order_id' => $order->id, 'quantity' => 1, 'category' => ComplaintCategory::Other->value, 'description' => 'Responsibility-owned complaint', 'idempotency_key' => (string) Str::uuid()];
    }
}
