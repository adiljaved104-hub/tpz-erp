<?php

namespace Tests\Feature\Phase1C;

use App\Actions\Products\CreateProduct;
use App\Actions\Products\GenerateProductSku;
use App\DTOs\Products\CreateProductData;
use App\Enums\EmployeeRole;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ProductSkuGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_skus_are_unique_server_generated_and_never_reused(): void
    {
        $owner = $this->owner();
        $first = app(GenerateProductSku::class)->handle($owner);
        $second = app(GenerateProductSku::class)->handle($owner);

        $this->assertSame('TPZ-000001', $first);
        $this->assertSame('TPZ-000002', $second);
        $this->assertNotSame($first, $second);

        $brand = ProductBrand::factory()->create();
        $category = ProductCategory::factory()->create();

        $product = app(CreateProduct::class)->handle(new CreateProductData(
            name: 'Generated', brandId: $brand->id, categoryId: $category->id,
        ), $owner);
        $this->assertSame('TPZ-000003', $product->sku);
    }

    public function test_sku_is_immutable(): void
    {
        $product = Product::factory()->create(['sku' => 'TPZ-000001']);
        $product->forceFill(['sku' => 'TPZ-000002']);

        $this->expectException(RuntimeException::class);
        $product->save();
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role(EmployeeRole::Owner)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
