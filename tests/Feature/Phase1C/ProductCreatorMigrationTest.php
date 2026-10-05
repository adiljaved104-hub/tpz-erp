<?php

namespace Tests\Feature\Phase1C;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductCreatorMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_uses_earliest_valid_creation_actor_and_never_editor_or_unrelated_subjects(): void
    {
        $migration = $this->migration();
        $migration->down();
        $original = User::factory()->create();
        $later = User::factory()->create();
        $legacy = Product::factory()->create();
        $classNamed = Product::factory()->create();
        $unknown = Product::factory()->create();
        $nullOnly = Product::factory()->create();
        $this->event($legacy, 'product', 'product.created', $later->id, '2026-02-02 10:00:00');
        $this->event($legacy, 'product', 'product.updated', $later->id, '2026-01-01 10:00:00');
        $this->event($legacy, 'product', 'product.created', null, '2025-12-01 10:00:00');
        $this->event($legacy, 'product', 'product.created', $original->id, '2026-02-01 10:00:00');
        $this->event($legacy, 'product', 'product.created', $later->id, '2026-02-01 10:00:00');
        $this->event($classNamed, Product::class, 'product.created', $original->id, '2026-02-01 10:00:00');
        $this->event($unknown, 'product', 'product.updated', $later->id, '2026-01-01 10:00:00');
        $this->event($unknown, 'employee', 'product.created', $later->id, '2026-01-01 10:00:00');
        $this->event($nullOnly, 'product', 'product.created', null, '2026-01-01 10:00:00');
        $logsBefore = DB::table('activity_logs')->orderBy('id')->get()->toJson();
        $productsBefore = DB::table('products')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $migration->up();
        $this->assertSame($original->id, $legacy->fresh()->created_by_user_id);
        $this->assertSame($original->id, $classNamed->fresh()->created_by_user_id);
        $this->assertNull($unknown->fresh()->created_by_user_id);
        $this->assertNull($nullOnly->fresh()->created_by_user_id);
        $this->assertSame($logsBefore, DB::table('activity_logs')->orderBy('id')->get()->toJson());
        $this->assertSame($productsBefore, DB::table('products')->orderBy('id')->get()->map(function ($row): array {
            $values = (array) $row;
            unset($values['created_by_user_id']);

            return $values;
        })->all());
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame('ok', DB::scalar('PRAGMA integrity_check'));
        $migration->down();
        $this->assertFalse(Schema::hasColumn('products', 'created_by_user_id'));
        $migration->up();
        $this->assertSame($original->id, $legacy->fresh()->created_by_user_id);
    }

    public function test_creator_column_is_nullable_indexed_and_foreign_key_retains_product_on_user_deletion(): void
    {
        $creator = User::factory()->create();
        $product = Product::factory()->create(['created_by_user_id' => $creator->id]);
        $indexes = collect(Schema::getIndexes('products'))->keyBy('name');
        $this->assertSame(['created_by_user_id'], $indexes['products_creator_idx']['columns']);
        $foreign = collect(Schema::getForeignKeys('products'))->first(fn ($fk) => $fk['columns'] === ['created_by_user_id']);
        $this->assertSame('users', $foreign['foreign_table']);
        $this->assertSame('set null', $foreign['on_delete']);
        $creator->delete();
        $this->assertNull($product->fresh()->created_by_user_id);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_invalid_creator_id_is_rejected_by_database_constraint(): void
    {
        $product = Product::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('products')->where('id', $product->id)->update(['created_by_user_id' => 999999]);
    }

    private function event(Product $product, string $type, string $event, ?int $actor, string $date): void
    {
        DB::table('activity_logs')->insert(['event' => $event, 'subject_type' => $type, 'subject_id' => $product->id,
            'actor_user_id' => $actor, 'created_at' => $date, 'updated_at' => $date]);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_05_090000_add_original_creator_to_products.php');
    }
}
