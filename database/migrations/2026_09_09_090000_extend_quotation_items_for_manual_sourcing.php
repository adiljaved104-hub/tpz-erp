<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteTable(true);

            return;
        }

        DB::statement('ALTER TABLE quotation_items MODIFY product_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE quotation_items MODIFY sku VARCHAR(80) NULL');
        Schema::table('quotation_items', function (Blueprint $table): void {
            $table->string('source_type', 32)->default('existing_product')->after('product_id');
            $table->string('category_name', 120)->nullable()->after('model_name');
            $table->foreignId('manual_brand_id')->nullable()->after('category_name')->constrained('product_brands')->restrictOnDelete();
            $table->foreignId('manual_category_id')->nullable()->after('manual_brand_id')->constrained('product_categories')->restrictOnDelete();
            $table->string('manual_condition', 32)->nullable()->after('manual_category_id');
            $table->foreignId('materialized_product_id')->nullable()->after('manual_condition')->unique('quotation_items_materialized_product_uq')->constrained('products')->restrictOnDelete();
            $table->foreignId('materialized_by_user_id')->nullable()->after('materialized_product_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('materialized_at')->nullable()->after('materialized_by_user_id');
            $table->index(['source_type', 'quotation_id'], 'quotation_items_source_quote_idx');
        });
        DB::statement("ALTER TABLE quotation_items ADD CONSTRAINT quotation_items_source_chk CHECK ((source_type = 'existing_product' AND product_id IS NOT NULL AND sku IS NOT NULL AND manual_brand_id IS NULL AND manual_category_id IS NULL AND manual_condition IS NULL) OR (source_type = 'manual_sourced' AND product_id IS NULL AND sku IS NULL AND manual_brand_id IS NOT NULL AND manual_category_id IS NOT NULL AND manual_condition IN ('new','renewed','used','open_box','refurbished'))) ");
        DB::statement("ALTER TABLE quotation_items ADD CONSTRAINT quotation_items_materialized_chk CHECK ((materialized_product_id IS NULL AND materialized_by_user_id IS NULL AND materialized_at IS NULL) OR (source_type = 'manual_sourced' AND materialized_product_id IS NOT NULL AND materialized_by_user_id IS NOT NULL AND materialized_at IS NOT NULL))");
    }

    public function down(): void
    {
        if (DB::table('quotation_items')->where('source_type', 'manual_sourced')->exists()
            || DB::table('quotation_items')->whereNotNull('materialized_product_id')->exists()) {
            throw new RuntimeException('Rollback refused: manual quotation Product history exists.');
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteTable(false);

            return;
        }

        DB::statement('ALTER TABLE quotation_items DROP CHECK quotation_items_materialized_chk');
        DB::statement('ALTER TABLE quotation_items DROP CHECK quotation_items_source_chk');
        Schema::table('quotation_items', function (Blueprint $table): void {
            $table->dropForeign(['manual_brand_id']);
            $table->dropForeign(['manual_category_id']);
            $table->dropForeign(['materialized_product_id']);
            $table->dropForeign(['materialized_by_user_id']);
            $table->dropUnique('quotation_items_materialized_product_uq');
            $table->dropIndex('quotation_items_source_quote_idx');
            $table->dropColumn([
                'source_type', 'category_name', 'manual_brand_id', 'manual_category_id', 'manual_condition',
                'materialized_product_id', 'materialized_by_user_id', 'materialized_at',
            ]);
        });
        DB::statement('ALTER TABLE quotation_items MODIFY product_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE quotation_items MODIFY sku VARCHAR(80) NOT NULL');
    }

    private function rebuildSqliteTable(bool $extended): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement('PRAGMA legacy_alter_table = ON');

        try {
            DB::statement('ALTER TABLE quotation_items RENAME TO quotation_items_rebuild');
            DB::statement($extended ? $this->extendedSqliteTable() : $this->originalSqliteTable());
            $baseColumns = 'id, quotation_id, product_id, sku, product_name, brand_name, model_name, description, quantity, unit_price_including_vat, discount_amount, vat_rate, subtotal_excluding_vat, vat_amount, total_including_vat, line_number, created_at, updated_at';
            if ($extended) {
                DB::statement("INSERT INTO quotation_items ({$baseColumns}, source_type) SELECT {$baseColumns}, 'existing_product' FROM quotation_items_rebuild");
            } else {
                DB::statement("INSERT INTO quotation_items ({$baseColumns}) SELECT {$baseColumns} FROM quotation_items_rebuild");
            }
            DB::statement('DROP TABLE quotation_items_rebuild');
            DB::statement('CREATE INDEX quotation_items_product_quote_idx ON quotation_items(product_id,quotation_id)');
            if ($extended) {
                DB::statement('CREATE INDEX quotation_items_source_quote_idx ON quotation_items(source_type,quotation_id)');
            }
        } finally {
            DB::statement('PRAGMA legacy_alter_table = OFF');
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    private function extendedSqliteTable(): string
    {
        return <<<'SQL'
CREATE TABLE quotation_items (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 quotation_id INTEGER NOT NULL,
 product_id INTEGER NULL,
 source_type VARCHAR(32) NOT NULL DEFAULT 'existing_product',
 sku VARCHAR(80) NULL,
 product_name VARCHAR(255) NOT NULL,
 brand_name VARCHAR(120) NULL,
 model_name VARCHAR(120) NULL,
 category_name VARCHAR(120) NULL,
 manual_brand_id INTEGER NULL,
 manual_category_id INTEGER NULL,
 manual_condition VARCHAR(32) NULL,
 materialized_product_id INTEGER NULL UNIQUE,
 materialized_by_user_id INTEGER NULL,
 materialized_at DATETIME NULL,
 description VARCHAR(500) NOT NULL,
 quantity INTEGER NOT NULL CHECK(quantity > 0),
 unit_price_including_vat NUMERIC(15,2) NOT NULL CHECK(unit_price_including_vat > 0),
 discount_amount NUMERIC(15,2) NOT NULL DEFAULT 0 CHECK(discount_amount >= 0 AND discount_amount < (quantity * unit_price_including_vat)),
 vat_rate NUMERIC(7,4) NOT NULL DEFAULT 5 CHECK(vat_rate >= 0 AND vat_rate <= 100),
 subtotal_excluding_vat NUMERIC(15,2) NOT NULL CHECK(subtotal_excluding_vat >= 0),
 vat_amount NUMERIC(15,2) NOT NULL CHECK(vat_amount >= 0),
 total_including_vat NUMERIC(15,2) NOT NULL CHECK(total_including_vat > 0),
 line_number INTEGER NOT NULL CHECK(line_number > 0),
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 UNIQUE(quotation_id,line_number),
 CHECK((source_type = 'existing_product' AND product_id IS NOT NULL AND sku IS NOT NULL AND manual_brand_id IS NULL AND manual_category_id IS NULL AND manual_condition IS NULL) OR (source_type = 'manual_sourced' AND product_id IS NULL AND sku IS NULL AND manual_brand_id IS NOT NULL AND manual_category_id IS NOT NULL AND manual_condition IN ('new','renewed','used','open_box','refurbished'))),
 CHECK((materialized_product_id IS NULL AND materialized_by_user_id IS NULL AND materialized_at IS NULL) OR (source_type = 'manual_sourced' AND materialized_product_id IS NOT NULL AND materialized_by_user_id IS NOT NULL AND materialized_at IS NOT NULL)),
 FOREIGN KEY(quotation_id) REFERENCES quotations(id) ON DELETE RESTRICT,
 FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT,
 FOREIGN KEY(manual_brand_id) REFERENCES product_brands(id) ON DELETE RESTRICT,
 FOREIGN KEY(manual_category_id) REFERENCES product_categories(id) ON DELETE RESTRICT,
 FOREIGN KEY(materialized_product_id) REFERENCES products(id) ON DELETE RESTRICT,
 FOREIGN KEY(materialized_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL;
    }

    private function originalSqliteTable(): string
    {
        return <<<'SQL'
CREATE TABLE quotation_items (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 quotation_id INTEGER NOT NULL,
 product_id INTEGER NOT NULL,
 sku VARCHAR(80) NOT NULL,
 product_name VARCHAR(255) NOT NULL,
 brand_name VARCHAR(120) NULL,
 model_name VARCHAR(120) NULL,
 description VARCHAR(500) NOT NULL,
 quantity INTEGER NOT NULL CHECK(quantity > 0),
 unit_price_including_vat NUMERIC(15,2) NOT NULL CHECK(unit_price_including_vat > 0),
 discount_amount NUMERIC(15,2) NOT NULL DEFAULT 0 CHECK(discount_amount >= 0 AND discount_amount < (quantity * unit_price_including_vat)),
 vat_rate NUMERIC(7,4) NOT NULL DEFAULT 5 CHECK(vat_rate >= 0 AND vat_rate <= 100),
 subtotal_excluding_vat NUMERIC(15,2) NOT NULL CHECK(subtotal_excluding_vat >= 0),
 vat_amount NUMERIC(15,2) NOT NULL CHECK(vat_amount >= 0),
 total_including_vat NUMERIC(15,2) NOT NULL CHECK(total_including_vat > 0),
 line_number INTEGER NOT NULL CHECK(line_number > 0),
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 UNIQUE(quotation_id,line_number),
 FOREIGN KEY(quotation_id) REFERENCES quotations(id) ON DELETE RESTRICT,
 FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT
)
SQL;
    }
};
