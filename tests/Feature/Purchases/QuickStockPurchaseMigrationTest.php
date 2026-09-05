<?php

namespace Tests\Feature\Purchases;

use App\Enums\PurchaseEntryType;
use App\Models\Employee;
use App\Models\Purchase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class QuickStockPurchaseMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_has_entry_type_constraint_index_and_restrictive_handler_foreign_key(): void
    {
        $columns = collect(DB::select("PRAGMA table_info('purchases')"))->keyBy('name');
        $indexes = collect(DB::select("PRAGMA index_list('purchases')"))->pluck('name');
        $foreignKey = collect(DB::select("PRAGMA foreign_key_list('purchases')"))->firstWhere('from', 'handled_by_employee_id');
        $sql = DB::table('sqlite_master')->where('type', 'table')->where('name', 'purchases')->value('sql');

        $this->assertSame(1, $columns['entry_type']->notnull);
        $this->assertSame("'standard'", $columns['entry_type']->dflt_value);
        $this->assertSame(0, $columns['handled_by_employee_id']->notnull);
        $this->assertContains('purchases_entry_type_index', $indexes);
        $this->assertContains('purchases_handled_by_employee_id_index', $indexes);
        $this->assertSame('employees', $foreignKey->table);
        $this->assertSame('RESTRICT', strtoupper($foreignKey->on_delete));
        $this->assertStringContainsString("entry_type IN ('standard','quick_stock')", $sql);
    }

    public function test_existing_standard_purchase_survives_safe_rollback_and_reapply(): void
    {
        $purchase = Purchase::factory()->create(['entry_type' => PurchaseEntryType::Standard, 'handled_by_employee_id' => null]);
        $migration = $this->migration();
        $migration->down();

        $this->assertFalse(Schema::hasColumn('purchases', 'entry_type'));
        $this->assertDatabaseHas('purchases', ['id' => $purchase->id, 'reference' => $purchase->reference]);

        $migration->up();
        $this->assertTrue(Schema::hasColumn('purchases', 'entry_type'));
        $this->assertDatabaseHas('purchases', ['id' => $purchase->id, 'entry_type' => 'standard', 'handled_by_employee_id' => null]);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_rollback_refuses_quick_purchase_evidence(): void
    {
        Purchase::factory()->create(['entry_type' => PurchaseEntryType::QuickStock]);

        $this->expectException(RuntimeException::class);
        $this->migration()->down();
    }

    public function test_rollback_refuses_handler_evidence(): void
    {
        Purchase::factory()->create(['handled_by_employee_id' => Employee::factory()->create()->id]);

        $this->expectException(RuntimeException::class);
        $this->migration()->down();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_08_07_070938_add_entry_type_and_handled_by_employee_id_to_purchases_table.php');
    }
}
