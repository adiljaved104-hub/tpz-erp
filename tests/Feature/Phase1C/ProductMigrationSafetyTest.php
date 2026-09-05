<?php

namespace Tests\Feature\Phase1C;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class ProductMigrationSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_condition_aborts_before_mapping_data(): void
    {
        $this->rebuildLegacyProduct('Mystery', 'Active', 12);

        try {
            $this->remediationMigration()->up();
            $this->fail('Unknown conditions must abort migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Unknown Product condition', $exception->getMessage());
            $this->assertSame('Mystery', DB::table('products')->value('condition'));
        }
    }

    public function test_unknown_status_aborts_before_mapping_data(): void
    {
        $this->rebuildLegacyProduct('New', 'Archived', 12);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown Product status');
        $this->remediationMigration()->up();
    }

    public function test_out_of_range_warranty_aborts(): void
    {
        $this->rebuildLegacyProduct('New', 'Active', 601);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid warranty');
        $this->remediationMigration()->up();
    }

    private function rebuildLegacyProduct(string $condition, string $status, int $warranty): void
    {
        Schema::drop('products');
        (require database_path('migrations/2026_08_05_100023_create_products_table.php'))->up();
        DB::table('products')->insert([
            'sku' => 'TPZ-000001',
            'name' => 'Legacy',
            'brand' => 'HP',
            'category' => 'Laptop',
            'condition' => $condition,
            'warranty' => $warranty,
            'cost_price' => 451,
            'selling_price' => 0,
            'status' => $status,
        ]);
    }

    private function remediationMigration(): object
    {
        return require database_path('migrations/2026_08_06_102637_remediate_product_fields_and_enum_values.php');
    }
}
