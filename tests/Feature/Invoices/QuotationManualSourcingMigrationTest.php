<?php

namespace Tests\Feature\Invoices;

use App\Enums\EmployeeRole;
use App\Enums\ProductCondition;
use App\Enums\QuotationItemSourceType;
use App\Models\CompanyProfile;
use App\Models\Employee;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Quotations\QuotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class QuotationManualSourcingMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_supports_explicit_manual_snapshot_and_preserves_foreign_keys_and_checks(): void
    {
        $this->assertTrue(Schema::hasColumns('quotation_items', [
            'source_type', 'category_name', 'manual_brand_id', 'manual_category_id', 'manual_condition',
            'materialized_product_id', 'materialized_by_user_id', 'materialized_at',
        ]));
        $columns = collect(DB::select("PRAGMA table_info('quotation_items')"))->keyBy('name');
        $this->assertSame(0, $columns['product_id']->notnull);
        $this->assertSame(0, $columns['sku']->notnull);
        $this->assertSame("'existing_product'", $columns['source_type']->dflt_value);
        $foreignTables = collect(DB::select("PRAGMA foreign_key_list('quotation_items')"))->pluck('table')->all();
        foreach (['quotations', 'products', 'product_brands', 'product_categories', 'users'] as $table) {
            $this->assertContains($table, $foreignTables);
        }
        $indexes = collect(DB::select("PRAGMA index_list('quotation_items')"))->keyBy('name');
        $this->assertSame(1, $indexes['sqlite_autoindex_quotation_items_1']->unique);
        $this->assertSame(1, $indexes['sqlite_autoindex_quotation_items_2']->unique);
        $this->assertArrayHasKey('quotation_items_product_quote_idx', $indexes);
        $this->assertArrayHasKey('quotation_items_source_quote_idx', $indexes);
        $sql = (string) DB::scalar("SELECT sql FROM sqlite_master WHERE type='table' AND name='quotation_items'");
        $this->assertStringContainsString("source_type = 'manual_sourced'", $sql);
        $this->assertStringContainsString("manual_condition IN ('new','renewed','used','open_box','refurbished')", $sql);
        $this->assertSame('ok', DB::scalar('PRAGMA integrity_check'));
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_existing_quotation_item_is_unchanged_across_safe_rollback_and_reapply(): void
    {
        [$quote, $product] = $this->existingQuote();
        $before = DB::table('quotation_items')->where('quotation_id', $quote->id)->first();
        $migration = require database_path('migrations/2026_09_09_090000_extend_quotation_items_for_manual_sourcing.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('quotation_items', 'source_type'));
        $legacy = DB::table('quotation_items')->where('quotation_id', $quote->id)->first();
        $this->assertSame($before->id, $legacy->id);
        $this->assertSame($product->id, $legacy->product_id);
        $this->assertSame($before->description, $legacy->description);

        $migration->up();
        $after = DB::table('quotation_items')->where('quotation_id', $quote->id)->first();
        $this->assertSame($before->id, $after->id);
        $this->assertSame($product->id, $after->product_id);
        $this->assertSame('existing_product', $after->source_type);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_manual_history_blocks_destructive_rollback(): void
    {
        [$owner, $warehouse, $product] = $this->context();
        app(QuotationService::class)->create([
            'warehouse_id' => $warehouse->id,
            'document_type' => 'quotation',
            'quotation_date' => today()->toDateString(),
            'valid_until' => today()->addWeek()->toDateString(),
            'customer_name' => 'Manual Customer',
            'idempotency_key' => (string) Str::uuid(),
            'items' => [[
                'source_type' => QuotationItemSourceType::ManualSourced->value,
                'description' => 'Manual laptop',
                'manual_brand_id' => $product->brand_id,
                'manual_category_id' => $product->category_id,
                'manual_condition' => ProductCondition::New->value,
                'quantity' => 1,
                'unit_price_including_vat' => '500.00',
                'discount_amount' => '0.00',
                'vat_rate' => '5.0000',
                'purchase_unit_cost' => '300.0000',
            ]],
        ], $owner);

        $this->expectException(RuntimeException::class);
        (require database_path('migrations/2026_09_09_090000_extend_quotation_items_for_manual_sourcing.php'))->down();
    }

    private function existingQuote(): array
    {
        [$owner, $warehouse, $product] = $this->context();
        $quote = app(QuotationService::class)->create([
            'warehouse_id' => $warehouse->id,
            'document_type' => 'quotation',
            'quotation_date' => today()->toDateString(),
            'valid_until' => today()->addWeek()->toDateString(),
            'customer_name' => 'Existing Customer',
            'idempotency_key' => (string) Str::uuid(),
            'items' => [[
                'product_id' => $product->id,
                'description' => 'Existing laptop',
                'quantity' => 1,
                'unit_price_including_vat' => '500.00',
                'discount_amount' => '0.00',
                'vat_rate' => '5.0000',
            ]],
        ], $owner);

        return [$quote, $product];
    }

    private function context(): array
    {
        $owner = User::factory()->create(['email' => 'migration-owner-'.Str::uuid().'@techpointzone.com']);
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email, 'status' => true]);
        CompanyProfile::query()->create(['id' => 1, 'company_name_en' => 'Migration Test Company', 'trn' => '100000000000001', 'address_en' => 'Dubai', 'updated_by_user_id' => $owner->id]);

        return [$owner->refresh(), Warehouse::factory()->create(), Product::factory()->create(['selling_price' => '500.00'])];
    }
}
