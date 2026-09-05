<?php

namespace Tests\Feature\Phase1C;

use App\Actions\Products\UpdateProduct;
use App\DTOs\Products\UpdateProductData;
use App\Enums\EmployeeRole;
use App\Enums\ProductCondition;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_financial_change_log_contains_field_names_but_not_values(): void
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        $product = Product::factory()->create(['cost_price' => null, 'selling_price' => '0.00']);

        app(UpdateProduct::class)->handle($product, new UpdateProductData(
            name: $product->name,
            brandId: $product->brand_id,
            categoryId: $product->category_id,
            condition: ProductCondition::New,
            warranty: 12,
            costPrice: '451.1234',
            costPriceProvided: true,
            sellingPrice: '999.99',
            sellingPriceProvided: true,
        ), $owner);

        $properties = ActivityLog::query()->where('event', 'product.financial_fields_changed')->firstOrFail()->properties;
        $encoded = json_encode($properties);
        $this->assertContains('cost_price', $properties['changed_fields']);
        $this->assertContains('selling_price', $properties['changed_fields']);
        $this->assertStringNotContainsString('451.1234', $encoded);
        $this->assertStringNotContainsString('999.99', $encoded);
    }
}
