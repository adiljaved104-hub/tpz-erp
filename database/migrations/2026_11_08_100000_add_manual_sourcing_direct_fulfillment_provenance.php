<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('web_sales_manual_sourcing_lines', function (Blueprint $table): void {
            $table->boolean('auto_purchase_on_ship')->default(false);
            $table->decimal('supplier_unit_cost', 15, 4)->nullable();
            $table->string('supplier_currency', 3)->nullable();
            $table->decimal('fx_rate_to_aed', 16, 8)->nullable();
            $table->decimal('inventory_unit_cost_aed', 15, 4)->nullable();
            $table->date('purchased_on')->nullable();
            $table->uuid('shipment_idempotency_key')->nullable()->unique('wsm_ship_key_uq');
            $table->timestamp('shipment_posted_at')->nullable();
        });
        Schema::table('purchases', function (Blueprint $table): void {
            $table->foreignId('manual_sourcing_line_id')->nullable()->unique('purchase_manual_sourcing_line_uq');
            $table->foreign('manual_sourcing_line_id', 'purchase_manual_sourcing_line_fk')->references('id')->on('web_sales_manual_sourcing_lines')->restrictOnDelete();
        });
        Schema::table('purchase_receipts', function (Blueprint $table): void {
            $table->string('fulfillment_type', 32)->default('warehouse');
            $table->foreignId('source_order_item_id')->nullable();
            $table->foreign('source_order_item_id', 'pr_direct_order_item_fk')->references('id')->on('order_items')->restrictOnDelete();
        });
        Schema::table('order_fulfillment_items', function (Blueprint $table): void {
            $table->string('fulfillment_type', 32)->default('warehouse');
        });

        // Direct-to-customer stock events are NOT warehouse stock movements.
        // They record receipt and immediate consumption without changing sellable warehouse inventory.
        Schema::create('direct_fulfillment_stock_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_item_id');
            $table->foreignId('purchase_receipt_item_id');
            $table->string('event_type', 32);
            $table->integer('quantity_delta');
            $table->decimal('unit_cost_aed', 15, 4);
            $table->foreignId('recorded_by_user_id');
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['order_item_id', 'event_type'], 'df_stock_item_event_uq');
            $table->foreign('order_item_id', 'df_stock_order_item_fk')->references('id')->on('order_items')->restrictOnDelete();
            $table->foreign('purchase_receipt_item_id', 'df_stock_grn_item_fk')->references('id')->on('purchase_receipt_items')->restrictOnDelete();
            $table->foreign('recorded_by_user_id', 'df_stock_user_fk')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('direct_fulfillment_stock_events') && DB::table('direct_fulfillment_stock_events')->exists()) {
            throw new RuntimeException('Rollback refused: direct fulfillment stock events exist.');
        }
        if (Schema::hasTable('purchases') && DB::table('purchases')->whereNotNull('manual_sourcing_line_id')->exists()) {
            throw new RuntimeException('Rollback refused: manual sourcing purchases exist.');
        }
        if (Schema::hasTable('web_sales_manual_sourcing_lines') && DB::table('web_sales_manual_sourcing_lines')->where('auto_purchase_on_ship', true)->exists()) {
            throw new RuntimeException('Rollback refused: auto-post manual sourcing lines exist.');
        }
        Schema::dropIfExists('direct_fulfillment_stock_events');
        Schema::table('order_fulfillment_items', fn (Blueprint $table) => $table->dropColumn('fulfillment_type'));
        Schema::table('purchase_receipts', function (Blueprint $table): void {
            $table->dropForeign('pr_direct_order_item_fk');
            $table->dropColumn(['source_order_item_id', 'fulfillment_type']);
        });
        Schema::table('purchases', function (Blueprint $table): void {
            $table->dropForeign('purchase_manual_sourcing_line_fk');
            $table->dropUnique('purchase_manual_sourcing_line_uq');
            $table->dropColumn('manual_sourcing_line_id');
        });
        Schema::table('web_sales_manual_sourcing_lines', function (Blueprint $table): void {
            $table->dropUnique('wsm_ship_key_uq');
            $table->dropColumn([
                'auto_purchase_on_ship', 'supplier_unit_cost', 'supplier_currency',
                'fx_rate_to_aed', 'inventory_unit_cost_aed', 'purchased_on',
                'shipment_idempotency_key', 'shipment_posted_at',
            ]);
        });
    }
};
