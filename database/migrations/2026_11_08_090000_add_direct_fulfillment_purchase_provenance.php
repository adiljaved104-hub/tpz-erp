<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Historical sourcing rows stay untouched; fields are nullable until
        // a new, explicit manual-sourcing shipment transaction is implemented.
        Schema::table('web_sales_manual_sourcing_lines', function (Blueprint $table): void {
            $table->decimal('actual_unit_cost', 15, 4)->nullable();
            $table->string('purchase_currency', 3)->nullable();
            $table->date('purchased_on')->nullable();
            $table->string('supplier_invoice_reference', 255)->nullable();
        });

        // A direct-to-customer GRN must be distinguished from physical receipt
        // into our warehouse. The posting service must enforce the semantics.
        if (DB::getDriverName() === 'sqlite') {
            // SQLite supports adding a nullable column with an inline REFERENCES clause.
            // Schema::table would rebuild the existing purchase_receipts table here and
            // try to recreate its reserved sqlite_autoindex_* name, failing migration.
            DB::statement('ALTER TABLE purchase_receipts ADD COLUMN custody_mode VARCHAR(32) NULL');
            DB::statement('ALTER TABLE purchase_receipts ADD COLUMN direct_fulfillment_order_id INTEGER NULL REFERENCES orders(id) ON DELETE RESTRICT');
        } else {
            Schema::table('purchase_receipts', function (Blueprint $table): void {
                $table->string('custody_mode', 32)->nullable();
                $table->foreignId('direct_fulfillment_order_id')->nullable();
                $table->foreign('direct_fulfillment_order_id', 'pr_direct_order_fk')
                    ->references('id')->on('orders')->restrictOnDelete();
            });
        }

        // Append-only links for each committed shipping event. Never infer
        // completed shipment from a purchase/GRN existing without this link.
        Schema::create('manual_sourcing_shipment_postings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sourcing_line_id');
            $table->foreignId('order_id');
            $table->foreignId('order_item_id');
            $table->foreignId('purchase_id');
            $table->foreignId('purchase_receipt_id');
            $table->unsignedInteger('quantity');
            $table->uuid('idempotency_key')->unique('mssp_idempotency_uq');
            $table->string('custody_mode', 32);
            $table->timestamp('posted_at');
            $table->foreignId('posted_by_user_id');
            $table->timestamps();

            $table->index(['sourcing_line_id', 'posted_at'], 'mssp_line_posted_idx');
            $table->foreign('sourcing_line_id', 'mssp_line_fk')
                ->references('id')->on('web_sales_manual_sourcing_lines')->restrictOnDelete();
            $table->foreign('order_id', 'mssp_order_fk')
                ->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('order_item_id', 'mssp_order_item_fk')
                ->references('id')->on('order_items')->restrictOnDelete();
            $table->foreign('purchase_id', 'mssp_purchase_fk')
                ->references('id')->on('purchases')->restrictOnDelete();
            $table->foreign('purchase_receipt_id', 'mssp_grn_fk')
                ->references('id')->on('purchase_receipts')->restrictOnDelete();
            $table->foreign('posted_by_user_id', 'mssp_user_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Preserve audit trail and purchase provenance: this migration is
        // intentionally forward-only once any shipment posting exists.
        if (Schema::hasTable('manual_sourcing_shipment_postings')
            && DB::table('manual_sourcing_shipment_postings')->exists()) {
            throw new RuntimeException('Rollback refused: manual sourcing shipment postings contain history.');
        }

        Schema::dropIfExists('manual_sourcing_shipment_postings');
        Schema::table('purchase_receipts', function (Blueprint $table): void {
            $table->dropForeign('pr_direct_order_fk');
            $table->dropColumn(['custody_mode', 'direct_fulfillment_order_id']);
        });
        Schema::table('web_sales_manual_sourcing_lines', function (Blueprint $table): void {
            $table->dropColumn(['actual_unit_cost', 'purchase_currency', 'purchased_on', 'supplier_invoice_reference']);
        });
    }
};
