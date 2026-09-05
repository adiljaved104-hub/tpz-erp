<?php

namespace Tests\Feature\ProductIntelligence;

use App\Actions\Products\CreateProduct;
use App\Actions\Products\UpdateProduct;
use App\DTOs\Products\CreateProductData;
use App\DTOs\Products\UpdateProductData;
use App\Enums\EmployeeRole;
use App\Filament\Resources\Products\Pages\CreateProduct as CreateProductPage;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ProductIntelligence\ProductDuplicateGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ProductDuplicatePreventionTest extends TestCase
{
    use RefreshDatabase;

    public function test_likely_duplicate_is_shown_before_save_with_reasons_and_review_action(): void
    {
        [$owner, $brand, $category, $existing] = $this->foundation();
        $this->actingAs($owner);

        $component = Livewire::test(CreateProductPage::class)->fillForm([
            'name' => 'HP EliteBook 840 G10 Core i7 16GB 512GB',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'condition' => 'new',
            'model' => 'EliteBook 840 G10',
            'processor' => 'Core i7 1355U',
            'ram' => '16GB',
            'storage' => '512GB NVMe',
            'graphics' => 'Intel Iris Xe',
            'warranty' => 12,
            'selling_price' => '0.00',
        ]);

        $component->assertSee('Possible Duplicate Products')
            ->assertSee($existing->sku)
            ->assertSee('Review / use existing Product')
            ->assertSee('Reason to Continue With a Separate Product');
    }

    public function test_likely_duplicate_requires_reason_and_never_auto_merges_or_creates(): void
    {
        [$owner, $brand, $category, $existing] = $this->foundation();
        $before = Product::query()->count();

        try {
            app(CreateProduct::class)->handle($this->data($brand, $category), $owner);
            $this->fail('A likely duplicate must require an explicit continuation reason.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('duplicate_override_reason', $exception->errors());
        }

        $this->assertSame($before, Product::query()->count());
        $this->assertTrue($existing->is(Product::query()->findOrFail($existing->id)));
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_authorized_continuation_creates_one_separate_product_and_audits_safe_decision(): void
    {
        [$owner, $brand, $category, $existing] = $this->foundation();
        $before = Product::query()->count();

        $created = app(CreateProduct::class)->handle($this->data($brand, $category, 'Different procurement batch and warranty specification.'), $owner);

        $this->assertSame($before + 1, Product::query()->count());
        $this->assertNotSame($existing->id, $created->id);
        $this->assertSame('HP EliteBook 840 G10 Core i7 16GB 512GB', $existing->refresh()->name);
        $audit = ActivityLog::query()->where('event', 'product.duplicate_warning_overridden')->where('subject_id', $created->id)->sole();
        $this->assertSame([$existing->id], $audit->properties['candidate_product_ids']);
        $this->assertSame('Different procurement batch and warranty specification.', $audit->properties['reason']);
        $this->assertArrayNotHasKey('cost_price', $audit->properties);
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_current_product_is_excluded_when_editing_itself(): void
    {
        [$owner, $brand, , $existing] = $this->foundation();

        $candidates = app(ProductDuplicateGuard::class)->candidates($owner, [
            'name' => $existing->name,
            'brand_id' => $brand->id,
            'model' => $existing->model,
            'processor' => $existing->processor,
            'ram' => $existing->ram,
            'storage' => $existing->storage,
            'graphics' => $existing->graphics,
        ], $existing->id);

        $this->assertCount(0, $candidates);
    }

    public function test_edit_to_likely_duplicate_requires_reason_and_preserves_both_records(): void
    {
        [$owner, $brand, $category, $existing] = $this->foundation();
        $edited = Product::factory()->create(['brand' => 'HP', 'brand_id' => $brand->id, 'category' => 'Laptop', 'category_id' => $category->id, 'model' => 'ProBook 450']);
        $data = new UpdateProductData(
            name: $existing->name,
            brandId: $brand->id,
            categoryId: $category->id,
            condition: 'new',
            model: $existing->model,
            processor: $existing->processor,
            ram: $existing->ram,
            storage: $existing->storage,
            graphics: $existing->graphics,
        );

        try {
            app(UpdateProduct::class)->handle($edited, $data, $owner);
            $this->fail('Editing into a likely duplicate must require a reason.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('duplicate_override_reason', $exception->errors());
        }

        $updated = app(UpdateProduct::class)->handle($edited, new UpdateProductData(
            name: $data->name,
            brandId: $data->brandId,
            categoryId: $data->categoryId,
            condition: $data->condition,
            model: $data->model,
            processor: $data->processor,
            ram: $data->ram,
            storage: $data->storage,
            graphics: $data->graphics,
            duplicateOverrideReason: 'Separate supplier warranty identity.',
        ), $owner);

        $this->assertSame($existing->name, $updated->name);
        $this->assertSame(2, Product::query()->count());
        $this->assertSame(1, ActivityLog::query()->where('event', 'product.duplicate_warning_overridden')->where('subject_id', $edited->id)->count());
    }

    /** @return array{User, ProductBrand, ProductCategory, Product} */
    private function foundation(): array
    {
        $email = 'duplicate-owner-'.Str::lower(Str::random(8)).'@example.com';
        $owner = User::factory()->create(['email' => $email]);
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $email]);
        $brand = ProductBrand::factory()->create(['name' => 'HP', 'normalized_name' => 'hp']);
        $category = ProductCategory::factory()->create(['name' => 'Laptop', 'normalized_name' => 'laptop']);
        $existing = Product::factory()->create([
            'sku' => 'TPZ-EXISTING', 'name' => 'HP EliteBook 840 G10 Core i7 16GB 512GB',
            'brand' => 'HP', 'brand_id' => $brand->id, 'category' => 'Laptop', 'category_id' => $category->id,
            'model' => 'EliteBook 840 G10', 'processor' => 'Core i7 1355U', 'ram' => '16GB',
            'storage' => '512GB NVMe', 'graphics' => 'Intel Iris Xe',
        ]);

        return [$owner->refresh(), $brand, $category, $existing];
    }

    private function data(ProductBrand $brand, ProductCategory $category, ?string $reason = null): CreateProductData
    {
        return new CreateProductData(
            name: 'HP EliteBook 840 G10 Core i7 16GB 512GB',
            brandId: $brand->id,
            categoryId: $category->id,
            model: 'EliteBook 840 G10',
            processor: 'Core i7 1355U',
            ram: '16GB',
            storage: '512GB NVMe',
            graphics: 'Intel Iris Xe',
            duplicateOverrideReason: $reason,
        );
    }
}
