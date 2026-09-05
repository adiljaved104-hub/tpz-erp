<?php

namespace Tests\Feature\Phase1C;

use App\Actions\Products\CreateProduct;
use App\Actions\Products\SetProductStatus;
use App\Actions\Products\UpdateProduct;
use App\DTOs\Products\ChangeProductStatusData;
use App\DTOs\Products\CreateProductData;
use App\DTOs\Products\UpdateProductData;
use App\Enums\EmployeeRole;
use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductInventory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_creates_product_with_nullable_cost_and_no_inventory(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        [$brand, $category] = $this->catalog();
        $product = app(CreateProduct::class)->handle(new CreateProductData(
            name: 'Laptop', brandId: $brand->id, categoryId: $category->id, sellingPrice: '1000.00', sellingPriceProvided: true,
        ), $owner);

        $this->assertSame('TPZ-000001', $product->sku);
        $this->assertNull($product->cost_price);
        $this->assertSame('1000.00', $product->selling_price);
        $this->assertSame(0, ProductInventory::query()->count());
    }

    public function test_zero_cost_is_preserved_and_warranty_boundaries_are_validated(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        [$brand, $category] = $this->catalog();
        $product = app(CreateProduct::class)->handle(new CreateProductData(
            name: 'Free reference', brandId: $brand->id, categoryId: $category->id, warranty: 600,
            costPrice: '0.0000', costPriceProvided: true,
        ), $owner);

        $this->assertSame('0.0000', $product->cost_price);
        $this->assertSame(600, $product->warranty);

        foreach ([601, -1, 12.5, 'not numeric'] as $invalid) {
            try {
                app(CreateProduct::class)->handle(new CreateProductData(
                    name: 'Invalid', brandId: $brand->id, categoryId: $category->id, warranty: $invalid,
                ), $owner);
                $this->fail('Invalid warranty should fail.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_warranty_labels_follow_approved_rules(): void
    {
        $this->assertSame('No warranty', Product::warrantyLabel(0));
        $this->assertSame('1 year', Product::warrantyLabel(12));
        $this->assertSame('2 years', Product::warrantyLabel(24));
        $this->assertSame('18 months', Product::warrantyLabel(18));
    }

    public function test_general_update_cannot_change_status(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $product = Product::factory()->create(['status' => ProductStatus::Active]);
        $updated = app(UpdateProduct::class)->handle($product, new UpdateProductData(
            name: 'Updated', brandId: $product->brand_id, categoryId: $product->category_id, condition: ProductCondition::New,
        ), $owner);

        $this->assertSame(ProductStatus::Active, $updated->status);
    }

    public function test_all_approved_status_transitions_and_events(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $transitions = [
            [ProductStatus::Active, ProductStatus::Inactive, 'product.status_changed'],
            [ProductStatus::Active, ProductStatus::Discontinued, 'product.status_changed'],
            [ProductStatus::Inactive, ProductStatus::Active, 'product.status_changed'],
            [ProductStatus::Inactive, ProductStatus::Discontinued, 'product.status_changed'],
            [ProductStatus::Discontinued, ProductStatus::Active, 'product.reactivated'],
            [ProductStatus::Discontinued, ProductStatus::Inactive, 'product.status_changed'],
        ];

        foreach ($transitions as [$from, $to, $event]) {
            $product = Product::factory()->create(['status' => $from]);
            app(SetProductStatus::class)->handle($product, new ChangeProductStatusData($to, 'Approved transition'), $owner);
            $this->assertSame($to, $product->fresh()->status);
            $this->assertSame(1, ActivityLog::query()->where('subject_id', $product->id)->where('event', $event)->count());
        }
    }

    public function test_same_state_missing_reason_and_invalid_status_are_rejected(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $product = Product::factory()->create(['status' => ProductStatus::Active]);

        foreach ([
            new ChangeProductStatusData(ProductStatus::Active, 'Same'),
            new ChangeProductStatusData(ProductStatus::Inactive, '  '),
            new ChangeProductStatusData('archived', 'Invalid'),
        ] as $data) {
            try {
                app(SetProductStatus::class)->handle($product, $data, $owner);
                $this->fail('Invalid status request should fail.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);

        return $user->refresh();
    }

    /** @return array{ProductBrand, ProductCategory} */
    private function catalog(): array
    {
        return [ProductBrand::factory()->create(), ProductCategory::factory()->create()];
    }
}
