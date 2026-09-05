<?php

namespace Tests\Feature\Phase1C;

use App\Actions\Products\CreateProduct;
use App\Actions\Products\SetProductStatus;
use App\Contracts\ProductPermissionResolver;
use App\DTOs\Products\ChangeProductStatusData;
use App\DTOs\Products\CreateProductData;
use App\Enums\EmployeeRole;
use App\Enums\ProductPermission;
use App\Enums\ProductStatus;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Employee;
use App\Models\Product;
use App\Models\User;
use App\Services\Authorization\ProductAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_permission_matrix(): void
    {
        $authorization = app(ProductAuthorization::class);
        $owner = $this->user(EmployeeRole::Owner);
        $admin = $this->user(EmployeeRole::Admin);
        $manager = $this->user(EmployeeRole::Manager);
        $staff = $this->user(EmployeeRole::Staff);

        foreach (ProductPermission::cases() as $permission) {
            $this->assertTrue($authorization->allows($owner, $permission));
        }

        foreach ([ProductPermission::View, ProductPermission::Create, ProductPermission::Update,
            ProductPermission::ViewSellingPrice, ProductPermission::EditSellingPrice,
            ProductPermission::Activate, ProductPermission::Deactivate, ProductPermission::Export] as $permission) {
            $this->assertTrue($authorization->allows($admin, $permission));
        }

        foreach ([ProductPermission::ViewCostPrice, ProductPermission::EditCostPrice,
            ProductPermission::Discontinue, ProductPermission::Reactivate] as $permission) {
            $this->assertFalse($authorization->allows($admin, $permission));
        }

        $this->assertTrue($authorization->allows($manager, ProductPermission::View));
        $this->assertTrue($authorization->allows($manager, ProductPermission::ViewSellingPrice));
        $this->assertTrue($authorization->allows($manager, ProductPermission::Export));
        $this->assertFalse($authorization->allows($manager, ProductPermission::Update));

        foreach (ProductPermission::cases() as $permission) {
            $this->assertFalse($authorization->allows($staff, $permission));
        }
    }

    public function test_non_owner_resource_query_never_retrieves_null_or_non_null_cost(): void
    {
        Product::factory()->create(['cost_price' => null]);
        Product::factory()->create(['cost_price' => '99.1234']);
        $admin = $this->user(EmployeeRole::Admin);
        $this->actingAs($admin);

        foreach (ProductResource::getEloquentQuery()->get() as $product) {
            $this->assertArrayNotHasKey('cost_price', $product->getAttributes());
        }
    }

    public function test_unauthorized_cost_submission_is_rejected_not_ignored(): void
    {
        $admin = $this->user(EmployeeRole::Admin);

        $this->expectException(AuthorizationException::class);
        app(CreateProduct::class)->handle(new CreateProductData(
            name: 'Denied', brandId: 1, categoryId: 1, costPrice: '1.0000', costPriceProvided: true,
        ), $admin);
    }

    public function test_admin_cannot_discontinue_without_explicit_permission(): void
    {
        $admin = $this->user(EmployeeRole::Admin);
        $product = Product::factory()->create(['status' => ProductStatus::Active]);

        $this->expectException(AuthorizationException::class);
        app(SetProductStatus::class)->handle(
            $product,
            new ChangeProductStatusData(ProductStatus::Discontinued, 'Not permitted'),
            $admin,
        );
    }

    public function test_future_resolver_can_grant_employee_permissions_without_workflow_changes(): void
    {
        $staff = $this->user(EmployeeRole::Staff);
        $this->app->instance(ProductPermissionResolver::class, new class implements ProductPermissionResolver
        {
            public function allows(User $user, ProductPermission $permission, ?Product $product = null): bool
            {
                return in_array($permission, [
                    ProductPermission::View,
                    ProductPermission::Update,
                    ProductPermission::ViewSellingPrice,
                    ProductPermission::Deactivate,
                    ProductPermission::Reactivate,
                    ProductPermission::Export,
                ], true);
            }
        });

        $authorization = app(ProductAuthorization::class);
        $this->assertTrue($authorization->allows($staff, ProductPermission::Update));
        $this->assertTrue($authorization->allows($staff, ProductPermission::Reactivate));
        $this->assertTrue($authorization->allows($staff, ProductPermission::Export));
        $this->assertFalse($authorization->allows($staff, ProductPermission::ViewCostPrice));
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
