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
            $this->createSqliteTables();

            return;
        }

        Schema::create('invoice_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('invoice_prefix', 30)->default('TP-INV');
            $table->unsignedBigInteger('starting_number')->default(9153);
            $table->decimal('vat_rate', 5, 2)->default(5);
            $table->text('terms_en');
            $table->text('terms_ar')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('tax_invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('invoice_number', 60)->unique();
            $table->string('order_reference')->nullable()->index();
            $table->date('invoice_date')->index();
            $table->string('customer_name');
            $table->text('customer_address')->nullable();
            $table->string('customer_trn', 50)->nullable()->index();
            $table->decimal('vat_rate', 5, 2);
            $table->decimal('subtotal_excluding_vat', 14, 2);
            $table->decimal('vat_amount', 14, 2);
            $table->decimal('grand_total', 14, 2);
            $table->json('seller_snapshot');
            $table->text('terms_en_snapshot');
            $table->text('terms_ar_snapshot')->nullable();
            $table->string('status', 20)->default('issued')->index();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('void_reason')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->timestamps();
            $table->index(['created_by_user_id', 'invoice_date']);
        });

        Schema::create('tax_invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_invoice_id')->constrained('tax_invoices')->restrictOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price_including_vat', 14, 2);
            $table->decimal('subtotal_excluding_vat', 14, 2);
            $table->decimal('vat_amount', 14, 2);
            $table->decimal('total_including_vat', 14, 2);
            $table->unsignedInteger('line_number');
            $table->timestamps();
            $table->unique(['tax_invoice_id', 'line_number']);
        });

        $length = DB::getDriverName() === 'sqlite' ? 'length' : 'CHAR_LENGTH';
        DB::statement("ALTER TABLE invoice_settings ADD CONSTRAINT invoice_settings_values_check CHECK ({$length}(TRIM(invoice_prefix)) > 0 AND starting_number > 0 AND vat_rate > 0 AND vat_rate <= 100)");
        DB::statement("ALTER TABLE tax_invoices ADD CONSTRAINT tax_invoices_values_check CHECK (status IN ('issued','void') AND vat_rate > 0 AND subtotal_excluding_vat >= 0 AND vat_amount >= 0 AND grand_total > 0 AND ((status = 'issued' AND voided_at IS NULL AND voided_by_user_id IS NULL AND void_reason IS NULL) OR (status = 'void' AND voided_at IS NOT NULL AND voided_by_user_id IS NOT NULL AND {$length}(TRIM(void_reason)) > 0)))");
        DB::statement('ALTER TABLE tax_invoice_items ADD CONSTRAINT tax_invoice_items_values_check CHECK (quantity > 0 AND unit_price_including_vat > 0 AND subtotal_excluding_vat >= 0 AND vat_amount >= 0 AND total_including_vat > 0 AND line_number > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_invoice_items');
        Schema::dropIfExists('tax_invoices');
        Schema::dropIfExists('invoice_settings');
    }

    private function createSqliteTables(): void
    {
        DB::statement("CREATE TABLE invoice_settings (id INTEGER PRIMARY KEY,invoice_prefix VARCHAR(30) NOT NULL DEFAULT 'TP-INV',starting_number INTEGER NOT NULL DEFAULT 9153,vat_rate NUMERIC NOT NULL DEFAULT 5,terms_en TEXT NOT NULL,terms_ar TEXT NULL,updated_by_user_id INTEGER NULL,created_at DATETIME NULL,updated_at DATETIME NULL,CHECK(length(trim(invoice_prefix)) > 0 AND starting_number > 0 AND vat_rate > 0 AND vat_rate <= 100),FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT)");
        DB::statement("CREATE TABLE tax_invoices (id INTEGER PRIMARY KEY AUTOINCREMENT,invoice_number VARCHAR(60) NOT NULL UNIQUE,order_reference VARCHAR(255) NULL,invoice_date DATE NOT NULL,customer_name VARCHAR(255) NOT NULL,customer_address TEXT NULL,customer_trn VARCHAR(50) NULL,vat_rate NUMERIC NOT NULL,subtotal_excluding_vat NUMERIC NOT NULL,vat_amount NUMERIC NOT NULL,grand_total NUMERIC NOT NULL,seller_snapshot TEXT NOT NULL,terms_en_snapshot TEXT NOT NULL,terms_ar_snapshot TEXT NULL,status VARCHAR(20) NOT NULL DEFAULT 'issued',created_by_user_id INTEGER NOT NULL,issued_at DATETIME NOT NULL,voided_at DATETIME NULL,voided_by_user_id INTEGER NULL,void_reason TEXT NULL,idempotency_key VARCHAR(36) NOT NULL UNIQUE,created_at DATETIME NULL,updated_at DATETIME NULL,CHECK(status IN ('issued','void') AND vat_rate > 0 AND subtotal_excluding_vat >= 0 AND vat_amount >= 0 AND grand_total > 0 AND ((status = 'issued' AND voided_at IS NULL AND voided_by_user_id IS NULL AND void_reason IS NULL) OR (status = 'void' AND voided_at IS NOT NULL AND voided_by_user_id IS NOT NULL AND length(trim(void_reason)) > 0))),FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,FOREIGN KEY(voided_by_user_id) REFERENCES users(id) ON DELETE RESTRICT)");
        DB::statement('CREATE INDEX tax_invoices_order_reference_index ON tax_invoices(order_reference)');
        DB::statement('CREATE INDEX tax_invoices_invoice_date_index ON tax_invoices(invoice_date)');
        DB::statement('CREATE INDEX tax_invoices_customer_trn_index ON tax_invoices(customer_trn)');
        DB::statement('CREATE INDEX tax_invoices_status_index ON tax_invoices(status)');
        DB::statement('CREATE INDEX tax_invoices_created_date_index ON tax_invoices(created_by_user_id,invoice_date)');
        DB::statement('CREATE TABLE tax_invoice_items (id INTEGER PRIMARY KEY AUTOINCREMENT,tax_invoice_id INTEGER NOT NULL,description VARCHAR(255) NOT NULL,quantity INTEGER NOT NULL,unit_price_including_vat NUMERIC NOT NULL,subtotal_excluding_vat NUMERIC NOT NULL,vat_amount NUMERIC NOT NULL,total_including_vat NUMERIC NOT NULL,line_number INTEGER NOT NULL,created_at DATETIME NULL,updated_at DATETIME NULL,UNIQUE(tax_invoice_id,line_number),CHECK(quantity > 0 AND unit_price_including_vat > 0 AND subtotal_excluding_vat >= 0 AND vat_amount >= 0 AND total_including_vat > 0 AND line_number > 0),FOREIGN KEY(tax_invoice_id) REFERENCES tax_invoices(id) ON DELETE RESTRICT)');
    }
};
