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
        } else {
            $this->createMysqlTables();
        }

        DB::table('reference_sequences')->insertOrIgnore([
            'key' => 'quotation:2026',
            'next_value' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        foreach (['quotation_email_deliveries', 'quotation_items', 'quotations'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Rollback refused: Quotation business records exist.');
            }
        }

        $sequence = DB::table('reference_sequences')->where('key', 'quotation:2026')->value('next_value');
        if ($sequence !== null && (int) $sequence !== 1) {
            throw new RuntimeException('Rollback refused: a Quotation reference has been allocated.');
        }

        Schema::dropIfExists('quotation_email_deliveries');
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
        DB::table('reference_sequences')->where('key', 'quotation:2026')->delete();
    }

    private function createMysqlTables(): void
    {
        Schema::create('quotations', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 32)->unique('quotations_reference_uq');
            $table->string('document_type', 24)->default('quotation');
            $table->string('status', 20)->default('draft');
            $table->date('quotation_date');
            $table->date('valid_until');
            $table->string('customer_name', 190);
            $table->string('customer_company', 190)->nullable();
            $table->string('customer_phone', 40)->nullable();
            $table->string('customer_email', 190)->nullable();
            $table->text('customer_address')->nullable();
            $table->string('customer_trn', 50)->nullable();
            $table->string('external_reference', 100)->nullable();
            $table->char('currency', 3)->default('AED');
            $table->decimal('subtotal_excluding_vat', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->json('seller_snapshot')->nullable();
            $table->text('terms_en_snapshot')->nullable();
            $table->text('terms_ar_snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('salesperson_employee_id');
            $table->foreignId('created_by_user_id');
            $table->foreignId('order_id')->nullable()->unique('quotations_order_uq');
            $table->foreignId('tax_invoice_id')->nullable()->unique('quotations_invoice_uq');
            $table->uuid('idempotency_key')->unique('quotations_idempotency_uq');
            $table->uuid('order_conversion_idempotency_key')->nullable()->unique('quotations_order_conversion_uq');
            $table->uuid('invoice_conversion_idempotency_key')->nullable()->unique('quotations_invoice_conversion_uq');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->foreign('salesperson_employee_id', 'quotations_salesperson_fk')->references('id')->on('employees')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'quotations_created_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('order_id', 'quotations_order_fk')->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('tax_invoice_id', 'quotations_invoice_fk')->references('id')->on('tax_invoices')->restrictOnDelete();
            $table->index(['status', 'quotation_date'], 'quotations_status_date_idx');
            $table->index(['salesperson_employee_id', 'status', 'valid_until'], 'quotations_sales_status_valid_idx');
            $table->index(['created_by_user_id', 'quotation_date'], 'quotations_creator_date_idx');
            $table->index(['document_type', 'quotation_date'], 'quotations_type_date_idx');
            $table->index('customer_phone', 'quotations_customer_phone_idx');
            $table->index('customer_email', 'quotations_customer_email_idx');
        });
        DB::statement("ALTER TABLE quotations ADD CONSTRAINT quotations_values_chk CHECK (document_type IN ('quotation','proforma_invoice') AND status IN ('draft','sent','accepted','rejected','expired','converted','cancelled') AND currency = 'AED' AND valid_until >= quotation_date AND subtotal_excluding_vat >= 0 AND discount_total >= 0 AND vat_amount >= 0 AND grand_total >= 0 AND (status = 'draft' OR grand_total > 0) AND ((status = 'converted' AND (order_id IS NOT NULL OR tax_invoice_id IS NOT NULL)) OR (status <> 'converted' AND order_id IS NULL AND tax_invoice_id IS NULL)))");

        Schema::create('quotation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_id');
            $table->foreignId('product_id');
            $table->string('sku', 80);
            $table->string('product_name', 255);
            $table->string('brand_name', 120)->nullable();
            $table->string('model_name', 120)->nullable();
            $table->string('description', 500);
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price_including_vat', 15, 2);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('vat_rate', 7, 4)->default(5);
            $table->decimal('subtotal_excluding_vat', 15, 2);
            $table->decimal('vat_amount', 15, 2);
            $table->decimal('total_including_vat', 15, 2);
            $table->unsignedInteger('line_number');
            $table->timestamps();

            $table->foreign('quotation_id', 'quotation_items_quotation_fk')->references('id')->on('quotations')->restrictOnDelete();
            $table->foreign('product_id', 'quotation_items_product_fk')->references('id')->on('products')->restrictOnDelete();
            $table->unique(['quotation_id', 'line_number'], 'quotation_items_line_uq');
            $table->index(['product_id', 'quotation_id'], 'quotation_items_product_quote_idx');
        });
        DB::statement('ALTER TABLE quotation_items ADD CONSTRAINT quotation_items_values_chk CHECK (quantity > 0 AND unit_price_including_vat > 0 AND discount_amount >= 0 AND discount_amount < (quantity * unit_price_including_vat) AND vat_rate >= 0 AND vat_rate <= 100 AND subtotal_excluding_vat >= 0 AND vat_amount >= 0 AND total_including_vat > 0 AND line_number > 0)');

        Schema::create('quotation_email_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_id');
            $table->string('recipient_email', 190);
            $table->string('subject', 255);
            $table->string('status', 16)->default('queued');
            $table->timestamp('requested_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('safe_error_code', 80)->nullable();
            $table->string('safe_error_message', 500)->nullable();
            $table->uuid('idempotency_key')->unique('quotation_email_idempotency_uq');
            $table->foreignId('requested_by_user_id');
            $table->timestamps();

            $table->foreign('quotation_id', 'quotation_email_quotation_fk')->references('id')->on('quotations')->restrictOnDelete();
            $table->foreign('requested_by_user_id', 'quotation_email_requested_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->index(['quotation_id', 'requested_at'], 'quotation_email_quote_requested_idx');
            $table->index(['status', 'created_at'], 'quotation_email_status_created_idx');
        });
        DB::statement("ALTER TABLE quotation_email_deliveries ADD CONSTRAINT quotation_email_values_chk CHECK (status IN ('queued','sent','failed') AND ((status = 'queued' AND sent_at IS NULL AND failed_at IS NULL) OR (status = 'sent' AND sent_at IS NOT NULL AND failed_at IS NULL) OR (status = 'failed' AND sent_at IS NULL AND failed_at IS NOT NULL)))");
    }

    private function createSqliteTables(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE quotations (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 reference VARCHAR(32) NOT NULL UNIQUE,
 document_type VARCHAR(24) NOT NULL DEFAULT 'quotation' CHECK(document_type IN ('quotation','proforma_invoice')),
 status VARCHAR(20) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','sent','accepted','rejected','expired','converted','cancelled')),
 quotation_date DATE NOT NULL,
 valid_until DATE NOT NULL,
 customer_name VARCHAR(190) NOT NULL,
 customer_company VARCHAR(190) NULL,
 customer_phone VARCHAR(40) NULL,
 customer_email VARCHAR(190) NULL,
 customer_address TEXT NULL,
 customer_trn VARCHAR(50) NULL,
 external_reference VARCHAR(100) NULL,
 currency CHAR(3) NOT NULL DEFAULT 'AED' CHECK(currency = 'AED'),
 subtotal_excluding_vat NUMERIC(15,2) NOT NULL DEFAULT 0 CHECK(subtotal_excluding_vat >= 0),
 discount_total NUMERIC(15,2) NOT NULL DEFAULT 0 CHECK(discount_total >= 0),
 vat_amount NUMERIC(15,2) NOT NULL DEFAULT 0 CHECK(vat_amount >= 0),
 grand_total NUMERIC(15,2) NOT NULL DEFAULT 0 CHECK(grand_total >= 0),
 seller_snapshot TEXT NULL,
 terms_en_snapshot TEXT NULL,
 terms_ar_snapshot TEXT NULL,
 notes TEXT NULL,
 salesperson_employee_id INTEGER NOT NULL,
 created_by_user_id INTEGER NOT NULL,
 order_id INTEGER NULL UNIQUE,
 tax_invoice_id INTEGER NULL UNIQUE,
 idempotency_key VARCHAR(36) NOT NULL UNIQUE,
 order_conversion_idempotency_key VARCHAR(36) NULL UNIQUE,
 invoice_conversion_idempotency_key VARCHAR(36) NULL UNIQUE,
 sent_at DATETIME NULL,
 accepted_at DATETIME NULL,
 rejected_at DATETIME NULL,
 converted_at DATETIME NULL,
 cancelled_at DATETIME NULL,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 CHECK(valid_until >= quotation_date),
 CHECK(status = 'draft' OR grand_total > 0),
 CHECK((status = 'converted' AND (order_id IS NOT NULL OR tax_invoice_id IS NOT NULL)) OR (status <> 'converted' AND order_id IS NULL AND tax_invoice_id IS NULL)),
 FOREIGN KEY(salesperson_employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE RESTRICT,
 FOREIGN KEY(tax_invoice_id) REFERENCES tax_invoices(id) ON DELETE RESTRICT
)
SQL);
        DB::statement('CREATE INDEX quotations_status_date_idx ON quotations(status,quotation_date)');
        DB::statement('CREATE INDEX quotations_sales_status_valid_idx ON quotations(salesperson_employee_id,status,valid_until)');
        DB::statement('CREATE INDEX quotations_creator_date_idx ON quotations(created_by_user_id,quotation_date)');
        DB::statement('CREATE INDEX quotations_type_date_idx ON quotations(document_type,quotation_date)');
        DB::statement('CREATE INDEX quotations_customer_phone_idx ON quotations(customer_phone)');
        DB::statement('CREATE INDEX quotations_customer_email_idx ON quotations(customer_email)');

        DB::statement(<<<'SQL'
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
SQL);
        DB::statement('CREATE INDEX quotation_items_product_quote_idx ON quotation_items(product_id,quotation_id)');

        DB::statement(<<<'SQL'
CREATE TABLE quotation_email_deliveries (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 quotation_id INTEGER NOT NULL,
 recipient_email VARCHAR(190) NOT NULL,
 subject VARCHAR(255) NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','sent','failed')),
 requested_at DATETIME NOT NULL,
 sent_at DATETIME NULL,
 failed_at DATETIME NULL,
 safe_error_code VARCHAR(80) NULL,
 safe_error_message VARCHAR(500) NULL,
 idempotency_key VARCHAR(36) NOT NULL UNIQUE,
 requested_by_user_id INTEGER NOT NULL,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 CHECK((status = 'queued' AND sent_at IS NULL AND failed_at IS NULL) OR (status = 'sent' AND sent_at IS NOT NULL AND failed_at IS NULL) OR (status = 'failed' AND sent_at IS NULL AND failed_at IS NOT NULL)),
 FOREIGN KEY(quotation_id) REFERENCES quotations(id) ON DELETE RESTRICT,
 FOREIGN KEY(requested_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
        DB::statement('CREATE INDEX quotation_email_quote_requested_idx ON quotation_email_deliveries(quotation_id,requested_at)');
        DB::statement('CREATE INDEX quotation_email_status_created_idx ON quotation_email_deliveries(status,created_at)');
    }
};
