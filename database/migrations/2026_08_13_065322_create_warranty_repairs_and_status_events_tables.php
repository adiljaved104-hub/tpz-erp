<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_repairs', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('marketplace_platform_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_return_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('damaged_stock_event_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_inventory_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('serial_number')->nullable();
            $table->string('source');
            $table->text('issue_description');
            $table->string('received_from')->nullable();
            $table->timestamp('received_at');
            $table->string('inspection_result')->nullable();
            $table->string('service_provider')->nullable();
            $table->string('external_service_reference')->nullable();
            foreach (['sent_to_technician_at', 'expected_return_at', 'repair_completed_at', 'received_back_at', 'qc_at', 'dispatched_back_at', 'completed_at'] as $column) {
                $table->timestamp($column)->nullable();
            }
            $table->string('dispatch_tracking_reference')->nullable();
            $table->unsignedInteger('moved_to_damaged_quantity')->nullable();
            $table->foreignId('moved_to_damaged_event_id')->nullable()->unique()->constrained('damaged_stock_events')->restrictOnDelete();
            $table->foreignId('moved_to_damaged_movement_id')->nullable()->unique()->constrained('stock_movements')->restrictOnDelete();
            $table->timestamp('moved_to_damaged_at')->nullable();
            $table->foreignId('moved_to_damaged_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('repair_qc_movement_id')->nullable()->unique()->constrained('stock_movements')->restrictOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status')->default('received');
            $table->text('notes')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['status', 'expected_return_at']);
            $table->index(['product_id', 'marketplace_platform_id']);
        });
        Schema::create('warranty_repair_status_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warranty_repair_id')->constrained()->restrictOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('note')->nullable();
            $table->foreignId('changed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('changed_at');
            $table->timestamps();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE warranty_repairs ADD CONSTRAINT warranty_repairs_quantity_check CHECK (quantity > 0)');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('warranty_repairs') && DB::table('warranty_repairs')->exists()) {
            throw new RuntimeException('Rollback refused: Warranty / Repair history exists.');
        }
        Schema::dropIfExists('warranty_repair_status_events');
        Schema::dropIfExists('warranty_repairs');
    }
};
