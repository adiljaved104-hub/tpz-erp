<?php

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\RenameProductBrand;
use App\Actions\Catalog\SetProductBrandStatus;
use App\Actions\Catalog\SetProductCategoryStatus;
use App\Actions\Products\CreateProduct;
use App\Actions\Products\UpdateProduct;
use App\DTOs\Catalog\ChangeCatalogStatusData;
use App\DTOs\Catalog\RenameCatalogItemData;
use App\DTOs\Products\CreateProductData;
use App\DTOs\Products\UpdateProductData;
use App\Enums\EmployeeRole;
use App\Enums\ProductCondition;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductCatalogIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_create_dual_writes_and_master_rename_preserves_legacy_snapshot(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $brand = ProductBrand::factory()->create(['name' => 'HP', 'normalized_name' => 'hp']);
        $category = ProductCategory::factory()->create(['name' => 'Laptop', 'normalized_name' => 'laptop']);
        $product = app(CreateProduct::class)->handle(new CreateProductData(name: 'Linked', brandId: $brand->id, categoryId: $category->id), $owner);

        $this->assertSame($brand->id, $product->brand_id);
        $this->assertSame('HP', $product->brand);
        $this->assertSame('Laptop', $product->category);
        app(RenameProductBrand::class)->handle($brand, new RenameCatalogItemData('Hewlett-Packard'), $owner);
        $this->assertSame('HP', $product->fresh()->brand);
        $this->assertSame('Hewlett-Packard', $product->fresh()->displayBrandName());
    }

    public function test_unchanged_inactive_relationships_save_but_other_inactive_values_cannot_be_assigned(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $product = Product::factory()->create();
        app(SetProductBrandStatus::class)->handle($product->brandRelation, new ChangeCatalogStatusData(false, 'Historical'), $owner);
        app(SetProductCategoryStatus::class)->handle($product->categoryRelation, new ChangeCatalogStatusData(false, 'Historical'), $owner);

        $updated = app(UpdateProduct::class)->handle($product, new UpdateProductData(
            name: 'Unrelated edit', brandId: $product->brand_id, categoryId: $product->category_id, condition: ProductCondition::New,
        ), $owner);
        $this->assertSame('Unrelated edit', $updated->name);

        $other = ProductBrand::factory()->create(['status' => false]);
        $this->expectException(ValidationException::class);
        app(UpdateProduct::class)->handle($updated, new UpdateProductData(
            name: $updated->name, brandId: $other->id, categoryId: $updated->category_id, condition: ProductCondition::New,
        ), $owner);
    }

    public function test_product_resource_eager_loads_catalog_without_n_plus_one_queries(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        Product::factory()->count(8)->create();
        $owner->load('employee');
        $this->actingAs($owner);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $products = ProductResource::getEloquentQuery()->get();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        foreach ($products as $product) {
            $product->displayBrandName();
            $product->displayCategoryName();
        }
        $this->assertCount(8, $products);
        $this->assertLessThanOrEqual(3, count($queries));
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
