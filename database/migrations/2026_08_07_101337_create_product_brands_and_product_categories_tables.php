<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_brands', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('normalized_name')->unique();
            $table->boolean('status')->default(true)->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('product_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('normalized_name')->unique();
            $table->boolean('status')->default(true)->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->assertCatalogIsSafeToRemove('product_brands', 'brand');
        $this->assertCatalogIsSafeToRemove('product_categories', 'category');

        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('product_brands');
    }

    private function assertCatalogIsSafeToRemove(string $table, string $legacyColumn): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $catalog = DB::table($table)->get(['normalized_name', 'status', 'created_by_user_id']);
        $expected = Schema::hasTable('products')
            ? DB::table('products')->pluck($legacyColumn)->map(fn (mixed $value): string => $this->normalize((string) $value))->unique()->sort()->values()
            : collect();
        $actual = $catalog->pluck('normalized_name')->sort()->values();
        $operational = $catalog->contains(fn (object $row): bool => $row->created_by_user_id !== null || ! (bool) $row->status);

        if ($operational || $actual->all() !== $expected->all()) {
            throw new RuntimeException("Rollback refused because {$table} contains operational catalog changes.");
        }
    }

    private function normalize(string $value): string
    {
        $value = preg_replace('/^\s+|\s+$/u', '', $value) ?? '';
        $collapsed = preg_replace('/\s+/u', ' ', $value);

        return mb_strtolower($collapsed ?? '', 'UTF-8');
    }
};
