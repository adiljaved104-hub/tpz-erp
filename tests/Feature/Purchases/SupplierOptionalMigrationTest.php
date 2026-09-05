<?php

namespace Tests\Feature\Purchases;

use App\Models\Purchase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class SupplierOptionalMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_has_nullable_supplier_external_reference_index_and_restrictive_foreign_key(): void
    {
        $supplier = collect(DB::select("PRAGMA table_info('purchases')"))->firstWhere('name', 'supplier_id');
        $indexes = collect(DB::select("PRAGMA index_list('purchases')"))->pluck('name');
        $foreignKey = collect(DB::select("PRAGMA foreign_key_list('purchases')"))->firstWhere('from', 'supplier_id');

        $this->assertSame(0, $supplier->notnull);
        $this->assertTrue(Schema::hasColumn('purchases', 'external_accounting_reference'));
        $this->assertContains('purchases_external_accounting_reference_index', $indexes);
        $this->assertSame('suppliers', $foreignKey->table);
        $this->assertSame('RESTRICT', strtoupper($foreignKey->on_delete));
    }

    public function test_rollback_refuses_supplierless_purchase(): void
    {
        Purchase::factory()->create(['supplier_id' => null]);

        $this->expectException(RuntimeException::class);
        $this->migration()->down();
    }

    public function test_rollback_refuses_external_accounting_reference(): void
    {
        Purchase::factory()->create(['external_accounting_reference' => 'QB-1']);

        $this->expectException(RuntimeException::class);
        $this->migration()->down();
    }

    public function test_safe_rollback_and_reapply_preserve_supplier_linked_purchase(): void
    {
        $purchase = Purchase::factory()->create();
        $migration = $this->migration();

        $migration->down();

        $supplier = collect(DB::select("PRAGMA table_info('purchases')"))->firstWhere('name', 'supplier_id');
        $this->assertSame(1, $supplier->notnull);
        $this->assertFalse(Schema::hasColumn('purchases', 'external_accounting_reference'));
        $this->assertDatabaseHas('purchases', ['id' => $purchase->id, 'supplier_id' => $purchase->supplier_id]);

        $migration->up();

        $this->assertTrue(Schema::hasColumn('purchases', 'external_accounting_reference'));
        $this->assertDatabaseHas('purchases', ['id' => $purchase->id, 'supplier_id' => $purchase->supplier_id]);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_08_07_043615_make_supplier_optional_and_add_external_accounting_reference_to_purchases_table.php');
    }
}
