<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class CatalogMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deduplicates_links_and_preserves_legacy_text_then_rolls_back_safely(): void
    {
        $this->removeCatalogSchema();
        $this->insertLegacyProduct(1, 'HP', 'Laptop');
        $this->insertLegacyProduct(2, ' hp ', 'Laptop');
        $this->insertLegacyProduct(3, 'Hp', 'All  in   One');
        $before = DB::table('products')->orderBy('id')->pluck('brand', 'id')->all();

        $this->mastersMigration()->up();
        $this->linksMigration()->up();

        $this->assertSame(1, DB::table('product_brands')->count());
        $this->assertSame(2, DB::table('product_categories')->count());
        $this->assertSame('hp', DB::table('product_brands')->value('normalized_name'));
        $this->assertSame(0, DB::table('products')->whereNull('brand_id')->orWhereNull('category_id')->count());
        $this->assertSame($before, DB::table('products')->orderBy('id')->pluck('brand', 'id')->all());

        $this->linksMigration()->down();
        $this->assertFalse(Schema::hasColumn('products', 'brand_id'));
        $this->assertSame($before, DB::table('products')->orderBy('id')->pluck('brand', 'id')->all());
        $this->mastersMigration()->down();
        $this->assertFalse(Schema::hasTable('product_brands'));
    }

    public function test_invalid_legacy_value_aborts_before_product_schema_changes(): void
    {
        $this->removeCatalogSchema();
        $this->insertLegacyProduct(1, '   ', 'Laptop');
        $this->mastersMigration()->up();

        try {
            $this->linksMigration()->up();
            $this->fail('Blank legacy catalog data must abort migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('invalid legacy brand', $exception->getMessage());
            $this->assertFalse(Schema::hasColumn('products', 'brand_id'));
        }
    }

    public function test_master_table_rollback_refuses_operational_records(): void
    {
        $user = User::factory()->create();
        DB::table('product_brands')->insert(['name' => 'Operational', 'normalized_name' => 'operational', 'status' => true, 'created_by_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->linksMigration()->down();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('operational catalog changes');
        $this->mastersMigration()->down();
    }

    private function removeCatalogSchema(): void
    {
        $this->linksMigration()->down();
        $this->mastersMigration()->down();
    }

    private function insertLegacyProduct(int $id, string $brand, string $category): void
    {
        DB::table('products')->insert(['id' => $id, 'sku' => "LEGACY-{$id}", 'name' => "Legacy {$id}", 'brand' => $brand, 'category' => $category, 'condition' => 'new', 'warranty' => 12, 'selling_price' => 0, 'status' => 'active']);
    }

    private function mastersMigration(): object
    {
        return require database_path('migrations/2026_08_07_101337_create_product_brands_and_product_categories_tables.php');
    }

    private function linksMigration(): object
    {
        return require database_path('migrations/2026_08_07_101351_add_brand_and_category_relationships_to_products.php');
    }
}
