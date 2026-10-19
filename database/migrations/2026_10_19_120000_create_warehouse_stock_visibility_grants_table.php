<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_stock_visibility_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('product_condition', 32);
            $table->string('status', 16)->default('active');
            $table->string('active_fingerprint', 64)->nullable()->unique();
            $table->timestamp('effective_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('assigned_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('ended_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->text('reason');
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['warehouse_id', 'product_condition', 'status']);
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE warehouse_stock_visibility_grants ADD CONSTRAINT warehouse_stock_visibility_grants_values_check CHECK (product_condition IN ('new', 'renewed', 'used', 'open_box', 'refurbished') AND status IN ('active', 'inactive') AND ((status = 'active' AND active_fingerprint IS NOT NULL AND ended_at IS NULL) OR (status = 'inactive' AND active_fingerprint IS NULL AND ended_at IS NOT NULL)))");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('warehouse_stock_visibility_grants') && DB::table('warehouse_stock_visibility_grants')->exists()) {
            throw new RuntimeException('Rollback refused: warehouse stock visibility history must be preserved.');
        }

        Schema::dropIfExists('warehouse_stock_visibility_grants');
    }
};
