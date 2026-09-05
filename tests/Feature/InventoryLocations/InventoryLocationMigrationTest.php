<?php

namespace Tests\Feature\InventoryLocations;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class InventoryLocationMigrationTest extends TestCase
{
    private string $connectionName;

    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = DB::getDefaultConnection();
        $this->connectionName = 'inventory_location_migration_test_'.str()->random(8);
        $configuration = config('database.connections.sqlite');
        $configuration['database'] = ':memory:';
        $configuration['foreign_key_constraints'] = true;
        config()->set("database.connections.{$this->connectionName}", $configuration);
        DB::setDefaultConnection($this->connectionName);
        DB::connection()->statement('PRAGMA foreign_keys = ON');

        $this->createPopulatedLegacySchema();
    }

    protected function tearDown(): void
    {
        DB::purge($this->connectionName);
        DB::setDefaultConnection($this->originalConnection);

        parent::tearDown();
    }

    public function test_additive_migration_preserves_populated_warehouse_references_with_foreign_keys_enabled(): void
    {
        $rootPageBefore = DB::table('sqlite_master')->where('type', 'table')->where('name', 'warehouses')->value('rootpage');
        $mainBefore = DB::table('warehouses')->where('id', 1)->first();
        $referencesBefore = $this->warehouseReferences();

        $this->migration()->up();

        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
        $this->assertSame($rootPageBefore, DB::table('sqlite_master')->where('type', 'table')->where('name', 'warehouses')->value('rootpage'));
        $this->assertSame([], DB::table('sqlite_master')->where('name', 'like', '%warehouses_inventory_locations%')->pluck('name')->all());

        foreach (['location_type', 'marketplace_platform_id', 'fulfillment_tag', 'created_by_user_id'] as $column) {
            $this->assertTrue(Schema::hasColumn('warehouses', $column));
        }

        $mainAfter = DB::table('warehouses')->where('id', 1)->first();
        $this->assertSame($mainBefore->id, $mainAfter->id);
        $this->assertSame($mainBefore->name, $mainAfter->name);
        $this->assertSame($mainBefore->code, $mainAfter->code);
        $this->assertSame($mainBefore->status, $mainAfter->status);
        $this->assertSame($mainBefore->is_default, $mainAfter->is_default);
        $this->assertSame('company_warehouse', $mainAfter->location_type);
        $this->assertNull($mainAfter->marketplace_platform_id);
        $this->assertNull($mainAfter->fulfillment_tag);
        $this->assertNull($mainAfter->created_by_user_id);
        $this->assertSame($referencesBefore, $this->warehouseReferences());
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_existing_and_new_indexes_and_new_restrictive_foreign_keys_are_enforced(): void
    {
        $this->migration()->up();

        $indexes = collect(DB::select("PRAGMA index_list('warehouses')"))->pluck('name')->all();
        $this->assertContains('warehouses_code_unique', $indexes);
        $this->assertContains('warehouses_status_index', $indexes);
        $this->assertContains('warehouses_is_default_index', $indexes);
        $this->assertContains('warehouses_location_type_index', $indexes);
        $this->assertContains('warehouses_marketplace_platform_id_index', $indexes);
        $this->assertContains('warehouses_created_by_user_id_index', $indexes);

        $foreignKeys = collect(DB::select("PRAGMA foreign_key_list('warehouses')"));
        $this->assertSame(['marketplace_platforms', 'users'], $foreignKeys->pluck('table')->sort()->values()->all());
        $this->assertSame(['RESTRICT'], $foreignKeys->pluck('on_delete')->unique()->values()->all());

        DB::table('marketplace_platforms')->insert(['id' => 7, 'name' => 'Amazon UAE']);
        DB::table('users')->insert(['id' => 8, 'name' => 'Owner']);
        DB::table('warehouses')->where('id', 1)->update(['marketplace_platform_id' => 7, 'created_by_user_id' => 8]);
        $this->assertDatabaseHas('warehouses', ['id' => 1, 'marketplace_platform_id' => 7, 'created_by_user_id' => 8]);

        $this->assertInvalidForeignKeyRejected('marketplace_platform_id');
        $this->assertInvalidForeignKeyRejected('created_by_user_id');
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_sqlite_rollback_refuses_to_rebuild_the_referenced_warehouse_table(): void
    {
        $this->migration()->up();

        try {
            $this->migration()->down();
            $this->fail('SQLite rollback must refuse a warehouse table rebuild.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('SQLite rollback refused', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('warehouses', 'location_type'));
        $this->assertSame($this->warehouseReferences(), [
            'product_inventories' => [1],
            'orders' => [1],
            'inventory_reservations' => [1],
            'order_fulfillment_items' => [1],
        ]);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_rollback_refuses_to_discard_operational_location_metadata(): void
    {
        $this->migration()->up();
        DB::table('warehouses')->where('id', 1)->update(['fulfillment_tag' => 'OPERATIONAL']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Inventory Location metadata is in use');
        $this->migration()->down();
    }

    private function assertInvalidForeignKeyRejected(string $column): void
    {
        try {
            DB::table('warehouses')->where('id', 1)->update([$column => 999999]);
            $this->fail("Invalid {$column} should be rejected by SQLite.");
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    /** @return array<string, array<int, int>> */
    private function warehouseReferences(): array
    {
        return collect(['product_inventories', 'orders', 'inventory_reservations', 'order_fulfillment_items'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->pluck('warehouse_id')->map(fn ($id): int => (int) $id)->all()])
            ->all();
    }

    private function createPopulatedLegacySchema(): void
    {
        DB::statement('CREATE TABLE "users" ("id" INTEGER PRIMARY KEY, "name" VARCHAR NOT NULL)');
        DB::statement('CREATE TABLE "marketplace_platforms" ("id" INTEGER PRIMARY KEY, "name" VARCHAR NOT NULL)');
        DB::statement(<<<'SQL'
            CREATE TABLE "warehouses" (
                "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                "name" VARCHAR NOT NULL,
                "code" VARCHAR NOT NULL,
                "address" TEXT,
                "status" TINYINT(1) NOT NULL DEFAULT '1',
                "is_default" TINYINT(1) NOT NULL DEFAULT '0',
                "created_at" DATETIME,
                "updated_at" DATETIME
            )
            SQL);
        DB::statement('CREATE UNIQUE INDEX "warehouses_code_unique" ON "warehouses" ("code")');
        DB::statement('CREATE INDEX "warehouses_status_index" ON "warehouses" ("status")');
        DB::statement('CREATE INDEX "warehouses_is_default_index" ON "warehouses" ("is_default")');

        foreach (['product_inventories', 'orders', 'inventory_reservations', 'order_fulfillment_items'] as $table) {
            DB::statement("CREATE TABLE \"{$table}\" (\"id\" INTEGER PRIMARY KEY, \"warehouse_id\" INTEGER NOT NULL REFERENCES \"warehouses\"(\"id\") ON DELETE RESTRICT)");
        }

        DB::table('warehouses')->insert([
            'id' => 1,
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'status' => 1,
            'is_default' => 1,
        ]);

        foreach (['product_inventories', 'orders', 'inventory_reservations', 'order_fulfillment_items'] as $table) {
            DB::table($table)->insert(['id' => 1, 'warehouse_id' => 1]);
        }
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_08_11_115011_extend_warehouses_for_inventory_locations.php');
    }
}
