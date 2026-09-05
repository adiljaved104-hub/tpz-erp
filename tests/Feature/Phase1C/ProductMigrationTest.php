<?php

namespace Tests\Feature\Phase1C;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class ProductMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_product_data_is_preserved_and_normalized(): void
    {
        Schema::drop('products');
        $this->legacyMigration()->up();
        DB::table('products')->insert([
            'id' => 2,
            'sku' => 'TPZ-000001',
            'name' => 'Hp 840 g4',
            'brand' => 'HP',
            'category' => 'Laptop',
            'condition' => 'New',
            'warranty' => 12,
            'cost_price' => 451,
            'selling_price' => 0,
            'status' => 'Active',
        ]);

        $this->remediationMigration()->up();
        $product = Product::query()->findOrFail(2);

        $this->assertSame('TPZ-000001', $product->sku);
        $this->assertSame('Hp 840 g4', $product->name);
        $this->assertSame('new', $product->condition->value);
        $this->assertSame('active', $product->status->value);
        $this->assertSame('451.0000', $product->cost_price);
        $this->assertSame('0.00', $product->selling_price);
        $this->assertSame(12, $product->warranty);
    }

    public function test_nullable_and_zero_cost_have_distinct_meanings(): void
    {
        $unknown = Product::factory()->create(['cost_price' => null]);
        $free = Product::factory()->create(['cost_price' => '0.0000']);

        $this->assertNull($unknown->fresh()->cost_price);
        $this->assertSame('0.0000', $free->fresh()->cost_price);
    }

    public function test_rollback_stops_for_null_or_four_decimal_cost(): void
    {
        Product::factory()->create(['cost_price' => null]);

        $this->expectException(RuntimeException::class);
        $this->remediationMigration()->down();
    }

    public function test_rollback_stops_before_truncating_four_decimal_cost(): void
    {
        Product::factory()->create(['cost_price' => '1.2345']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('without data loss');
        $this->remediationMigration()->down();
    }

    public function test_sqlite_rollback_succeeds_when_all_values_are_legacy_compatible(): void
    {
        Product::factory()->create(['cost_price' => '12.3400', 'selling_price' => '20.00']);

        $this->remediationMigration()->down();

        $product = DB::table('products')->first();
        $this->assertSame('New', $product->condition);
        $this->assertSame('Active', $product->status);
        $this->assertSame(12.34, (float) $product->cost_price);
    }

    public function test_sku_sequence_initializes_after_highest_canonical_suffix(): void
    {
        DB::table('reference_sequences')->where('key', 'product_sku')->delete();
        Product::factory()->create(['sku' => 'TPZ-000001']);

        $this->skuMigration()->up();

        $this->assertSame(2, (int) DB::table('reference_sequences')->where('key', 'product_sku')->value('next_value'));
    }

    private function legacyMigration(): object
    {
        return require database_path('migrations/2026_08_05_100023_create_products_table.php');
    }

    private function remediationMigration(): object
    {
        return require database_path('migrations/2026_08_06_102637_remediate_product_fields_and_enum_values.php');
    }

    private function skuMigration(): object
    {
        return require database_path('migrations/2026_08_06_102642_initialize_product_sku_sequence.php');
    }
}
