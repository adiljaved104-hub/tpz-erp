<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE tax_invoices ADD COLUMN source_order_id INTEGER NULL REFERENCES orders(id) ON DELETE RESTRICT');
            DB::statement('CREATE INDEX tax_invoices_source_order_id_index ON tax_invoices(source_order_id)');
        } else {
            Schema::table('tax_invoices', function (Blueprint $table): void {
                $table->foreignId('source_order_id')->nullable()->index()->constrained('orders')->restrictOnDelete();
            });
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteInvoiceItems(true);
        } else {
            Schema::table('tax_invoice_items', function (Blueprint $table): void {
                $table->foreignId('source_order_item_id')->nullable()->index()->constrained('order_items')->restrictOnDelete();
                $table->text('description')->change();
            });
        }

        Schema::table('invoice_settings', function (Blueprint $table): void {
            $table->text('quotation_terms_en')->nullable();
            $table->text('quotation_terms_ar')->nullable();
            $table->text('proforma_terms_en')->nullable();
            $table->text('proforma_terms_ar')->nullable();
        });

        DB::table('invoice_settings')->update([
            'quotation_terms_en' => DB::raw('terms_en'),
            'quotation_terms_ar' => DB::raw('terms_ar'),
            'proforma_terms_en' => DB::raw('terms_en'),
            'proforma_terms_ar' => DB::raw('terms_ar'),
        ]);
    }

    public function down(): void
    {
        if (DB::table('tax_invoice_items')->whereRaw('LENGTH(description) > 255')->exists()) {
            throw new RuntimeException('Rollback refused: an Invoice item description exceeds the previous 255-character limit.');
        }

        Schema::table('invoice_settings', function (Blueprint $table): void {
            $table->dropColumn(['quotation_terms_en', 'quotation_terms_ar', 'proforma_terms_en', 'proforma_terms_ar']);
        });

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteInvoiceItems(false);
        } else {
            Schema::table('tax_invoice_items', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('source_order_item_id');
                $table->string('description')->change();
            });
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX tax_invoices_source_order_id_index');
            DB::statement('ALTER TABLE tax_invoices DROP COLUMN source_order_id');
        } else {
            Schema::table('tax_invoices', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('source_order_id');
            });
        }
    }

    private function rebuildSqliteInvoiceItems(bool $withSource): void
    {
        $descriptionType = $withSource ? 'TEXT' : 'VARCHAR(255)';
        $sourceColumn = $withSource ? ',source_order_item_id INTEGER NULL' : '';
        $sourceForeignKey = $withSource ? ',FOREIGN KEY(source_order_item_id) REFERENCES order_items(id) ON DELETE RESTRICT' : '';

        DB::statement("CREATE TABLE tax_invoice_items_subphase_d (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tax_invoice_id INTEGER NOT NULL,
            description {$descriptionType} NOT NULL,
            quantity INTEGER NOT NULL,
            unit_price_including_vat NUMERIC NOT NULL,
            subtotal_excluding_vat NUMERIC NOT NULL,
            vat_amount NUMERIC NOT NULL,
            total_including_vat NUMERIC NOT NULL,
            line_number INTEGER NOT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
            {$sourceColumn},
            UNIQUE(tax_invoice_id,line_number),
            CHECK(quantity > 0 AND unit_price_including_vat > 0 AND subtotal_excluding_vat >= 0 AND vat_amount >= 0 AND total_including_vat > 0 AND line_number > 0),
            FOREIGN KEY(tax_invoice_id) REFERENCES tax_invoices(id) ON DELETE RESTRICT
            {$sourceForeignKey}
        )");

        $columns = 'id,tax_invoice_id,description,quantity,unit_price_including_vat,subtotal_excluding_vat,vat_amount,total_including_vat,line_number,created_at,updated_at';
        if (! $withSource) {
            $select = $columns;
        } else {
            $columns .= ',source_order_item_id';
            $select = 'id,tax_invoice_id,description,quantity,unit_price_including_vat,subtotal_excluding_vat,vat_amount,total_including_vat,line_number,created_at,updated_at,NULL';
        }
        DB::statement("INSERT INTO tax_invoice_items_subphase_d ({$columns}) SELECT {$select} FROM tax_invoice_items");
        Schema::drop('tax_invoice_items');
        Schema::rename('tax_invoice_items_subphase_d', 'tax_invoice_items');

        if ($withSource) {
            DB::statement('CREATE INDEX tax_invoice_items_source_order_item_id_index ON tax_invoice_items(source_order_item_id)');
        }
    }
};
