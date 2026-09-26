<?php

namespace Tests\Feature\ProductIntelligence;

use App\Actions\Products\CreateProduct;
use App\DTOs\ProductIntelligence\ProductMatchRequest;
use App\DTOs\Products\CreateProductData;
use App\Enums\EmployeeRole;
use App\Enums\ProductMatchContext;
use App\Filament\Resources\Products\Pages\ImportProducts;
use App\Models\Employee;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductMarketplaceListing;
use App\Models\User;
use App\Services\ProductIntelligence\ProductDuplicateGuard;
use App\Services\ProductIntelligence\ProductMatchService;
use App\Services\Products\ProductImportService;
use App\Services\Products\ProductTitleService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ProductTitlesAndImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_structured_product_fields_and_deterministic_titles_cover_optional_features_and_overrides(): void
    {
        [$owner, $brand, $category] = $this->foundation();
        $product = app(CreateProduct::class)->handle(new CreateProductData(
            name: 'Original safe name', brandId: $brand->id, categoryId: $category->id, model: 'Victus 15',
            processorClass: 'Core i5', processorModel: 'i5-13420H', processorGeneration: '13th Gen',
            ram: '16GB', storage: '512GB SSD', screenSize: '15.6"', graphics: 'RTX 4050', color: 'Blue',
            touchScreen: true, isConvertible360: true,
        ), $owner);

        $titles = app(ProductTitleService::class);
        $this->assertSame('HP Victus 15 Core i5 13th Gen 16GB/512GB SSD RTX 4050 Touch 360 Blue', $titles->accounting($product));
        $this->assertSame('HP Victus 15 15.6" Laptop – i5-13420H 16GB RAM 512GB SSD RTX 4050 Touch Screen 360 Convertible Blue', $titles->website($product));
        $this->assertTrue($product->touch_screen);
        $this->assertTrue($product->is_convertible_360);

        $product->forceFill(['accounting_title_override' => 'Accounts override', 'website_title_override' => 'Website override'])->save();
        $this->assertSame('Accounts override', $titles->accounting($product->refresh()));
        $this->assertSame('Website override', $titles->website($product));
    }

    public function test_legacy_and_non_laptop_products_use_safe_existing_name_fallback(): void
    {
        [, $brand, $category] = $this->foundation('Monitor');
        $product = Product::factory()->create(['name' => 'HP Series 5 Monitor', 'brand_id' => $brand->id, 'category_id' => $category->id, 'category' => 'Monitor']);
        $titles = app(ProductTitleService::class);
        $this->assertSame('HP Series 5 Monitor', $titles->accounting($product));
        $this->assertSame('HP Series 5 Monitor', $titles->website($product));
    }

    public function test_intelligent_search_matches_new_structured_specifications_and_color(): void
    {
        [$owner, $brand, $category] = $this->foundation();
        $product = Product::factory()->create([
            'name' => 'HP Pavilion', 'brand_id' => $brand->id, 'category_id' => $category->id,
            'model' => '15-eg3000', 'processor_class' => 'Core i5', 'processor_model' => 'i5-1334U',
            'processor_generation' => '13th Gen', 'ram' => '8GB', 'storage' => '512GB SSD', 'color' => 'Silver',
        ]);

        $results = app(ProductMatchService::class)->match(new ProductMatchRequest('i5-1334U Silver', ProductMatchContext::ProductCreation, $owner));
        $this->assertTrue($results->pluck('productId')->contains($product->id));
    }

    public function test_csv_preview_is_read_only_then_create_and_update_keep_sku_immutable(): void
    {
        [$owner] = $this->foundation();
        $service = app(ProductImportService::class);
        $path = $this->csv($service->templateHeaders(), ['', 'HP', 'Laptop', '15-fd0132wm', 'Core i5', 'i5-1334U', '13th Gen', '8GB', '512GB SSD', '15.6"', 'Integrated', 'Silver', 'No', 'No', 'New', '12', '1999.00', '', '']);
        $before = Product::query()->count();
        $preview = $service->preview($path, ProductImportService::MODE_CREATE, $owner);
        $this->assertSame($before, Product::query()->count());
        $this->assertSame(1, $preview['counts']['new']);
        $this->assertSame(0, $preview['counts']['invalid']);

        $result = $service->import($preview, ProductImportService::MODE_CREATE, $owner);
        $this->assertSame(['created' => 1, 'updated' => 0], $result);
        $product = Product::query()->latest('id')->firstOrFail();
        $sku = $product->sku;

        $updatePath = $this->csv($service->templateHeaders(), [$sku, 'HP', 'Laptop', '15-fd0132wm', 'Core i7', 'i7-1355U', '13th Gen', '16GB', '1TB SSD', '15.6"', 'Integrated', 'Blue', 'Yes', 'No', 'New', '24', '2499.00', '', '']);
        $updatePreview = $service->preview($updatePath, ProductImportService::MODE_UPSERT, $owner);
        $this->assertSame(1, $updatePreview['counts']['update']);
        $service->import($updatePreview, ProductImportService::MODE_UPSERT, $owner, 'Approved structured variant update.');
        $this->assertSame($sku, $product->refresh()->sku);
        $this->assertSame('i7-1355U', $product->processor_model);
        $this->assertSame('Blue', $product->color);
    }

    public function test_import_reports_exact_invalid_row_and_rejects_non_owner_admin(): void
    {
        [$owner] = $this->foundation();
        $service = app(ProductImportService::class);
        $path = $this->csv($service->templateHeaders(), ['', 'HPP', 'Laptop', '', '', '', '', '', '', '', '', '', 'Maybe', '', 'New', '12', '', '', '']);
        $preview = $service->preview($path, ProductImportService::MODE_CREATE, $owner);
        $this->assertSame(1, $preview['counts']['invalid']);
        $this->assertContains('Model is required.', $preview['rows'][0]['errors']);
        $this->assertContains('Brand "HPP" was not found.', $preview['rows'][0]['errors']);
        $this->assertContains('Touch must be Yes or No.', $preview['rows'][0]['errors']);

        $staff = $this->user(EmployeeRole::Staff);
        $this->expectException(HttpException::class);
        $service->preview($path, ProductImportService::MODE_CREATE, $staff);
    }

    public function test_xlsx_is_supported_and_variant_specific_duplicate_fields_do_not_collapse_color_or_touch_variants(): void
    {
        [$owner, $brand, $category] = $this->foundation();
        $existing = Product::factory()->create([
            'name' => 'HP Envy x360', 'brand_id' => $brand->id, 'category_id' => $category->id,
            'model' => 'Envy 14', 'processor_model' => 'Ultra 7 155H', 'ram' => '16GB', 'storage' => '1TB SSD',
            'graphics' => 'Integrated', 'screen_size' => '14"', 'color' => 'Silver', 'touch_screen' => true, 'is_convertible_360' => true,
        ]);
        $candidates = app(ProductDuplicateGuard::class)->candidates($owner, [
            'name' => $existing->name, 'brand_id' => $brand->id, 'model' => 'Envy 14', 'processor_model' => 'Ultra 7 155H',
            'ram' => '16GB', 'storage' => '1TB SSD', 'graphics' => 'Integrated', 'screen_size' => '14"',
            'color' => 'Blue', 'touch_screen' => false, 'is_convertible_360' => false, 'condition' => 'new',
        ]);
        $this->assertCount(0, $candidates);

        $service = app(ProductImportService::class);
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([$service->templateHeaders(), ['', 'HP', 'Laptop', 'ProBook 450', 'Core i5', 'i5-1335U', '13th Gen', '8GB', '512GB SSD', '15.6"', 'Integrated', 'Gray', 'No', 'No', 'New', '12', '', '', '']]);
        $path = tempnam(sys_get_temp_dir(), 'tpz-products-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $preview = $service->preview($path, ProductImportService::MODE_CREATE, $owner);
        $this->assertSame(1, $preview['counts']['new']);
        $this->assertSame(0, $preview['counts']['invalid']);
    }

    public function test_additive_migration_round_trip_preserves_legacy_products_without_guessing_processor_fields(): void
    {
        $legacy = Product::factory()->create(['processor' => 'Intel Core i5 10th Gen']);
        $migration = require database_path('migrations/2026_09_26_090000_add_product_intelligence_and_invoice_title_modes.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('products', 'processor_model'));
        $migration->up();

        $legacy = Product::query()->findOrFail($legacy->id);
        $this->assertSame('Intel Core i5 10th Gen', $legacy->processor);
        $this->assertNull($legacy->processor_class);
        $this->assertNull($legacy->processor_model);
        $this->assertTrue(Schema::hasTable('product_marketplace_listings'));
    }

    public function test_bulk_import_page_is_owner_admin_only_and_exposes_preview_first_workflow(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        Livewire::actingAs($owner)->test(ImportProducts::class)
            ->assertSee('Bulk Product Import')
            ->assertSee('Validate & Preview')
            ->assertSee('Download Import Template');

        $staff = $this->user(EmployeeRole::Staff);
        $this->actingAs($staff)->get(ImportProducts::getUrl())->assertForbidden();
    }

    public function test_marketplace_schema_allows_multiple_listings_per_product_platform_and_enforces_real_listing_identity(): void
    {
        $product = Product::factory()->create();
        $platform = MarketplacePlatform::factory()->create();
        ProductMarketplaceListing::query()->create([
            'product_id' => $product->id, 'marketplace_platform_id' => $platform->id,
            'marketplace_identifier' => 'ASIN-ONE', 'listing_sku' => 'AMZ-SKU-ONE', 'listing_title' => 'First listing',
        ]);
        ProductMarketplaceListing::query()->create([
            'product_id' => $product->id, 'marketplace_platform_id' => $platform->id,
            'marketplace_identifier' => 'ASIN-TWO', 'listing_sku' => 'AMZ-SKU-TWO', 'listing_title' => 'Second listing',
        ]);
        $this->assertSame(2, $product->marketplaceListings()->count());

        $duplicateProduct = Product::factory()->create();
        try {
            ProductMarketplaceListing::query()->create([
                'product_id' => $duplicateProduct->id, 'marketplace_platform_id' => $platform->id,
                'marketplace_identifier' => 'ASIN-ONE', 'listing_sku' => 'AMZ-SKU-THREE', 'listing_title' => 'Duplicate identity',
            ]);
            $this->fail('A Marketplace identifier must be unique within its Platform.');
        } catch (QueryException) {
            $this->assertDatabaseCount('product_marketplace_listings', 2);
        }

        try {
            ProductMarketplaceListing::query()->create([
                'product_id' => $duplicateProduct->id, 'marketplace_platform_id' => $platform->id,
                'marketplace_identifier' => 'ASIN-THREE', 'listing_sku' => 'AMZ-SKU-TWO', 'listing_title' => 'Duplicate SKU',
            ]);
            $this->fail('A listing SKU must be unique within its Platform.');
        } catch (QueryException) {
            $this->assertDatabaseCount('product_marketplace_listings', 2);
        }

        $longTitle = str_repeat('Long customer title ', 45);
        $product->forceFill(['website_title_override' => $longTitle])->save();
        $this->assertSame($longTitle, $product->refresh()->website_title_override);
        $this->assertSame(trim($longTitle), app(ProductTitleService::class)->marketplace($product, $platform->id));
    }

    /** @return array{User, ProductBrand, ProductCategory} */
    private function foundation(string $categoryName = 'Laptop'): array
    {
        $owner = $this->user(EmployeeRole::Owner);
        $brand = ProductBrand::factory()->create(['name' => 'HP', 'normalized_name' => 'hp']);
        $category = ProductCategory::factory()->create(['name' => $categoryName, 'normalized_name' => mb_strtolower($categoryName)]);

        return [$owner, $brand, $category];
    }

    private function user(EmployeeRole $role): User
    {
        $email = Str::lower(Str::random(10)).'@techpointzone.com';
        $user = User::factory()->create(['email' => $email]);
        Employee::factory()->for($user)->role($role)->create(['email' => $email, 'status' => true]);

        return $user->refresh();
    }

    /** @param array<int, string> $headers @param array<int, string> $row */
    private function csv(array $headers, array $row): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tpz-products-').'.csv';
        $stream = fopen($path, 'wb');
        fputcsv($stream, $headers);
        fputcsv($stream, $row);
        fclose($stream);

        return $path;
    }
}
