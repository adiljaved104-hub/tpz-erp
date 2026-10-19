<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_stock_visibility_grants', function (Blueprint $table): void {
            // Existing grants keep today's read-only behavior. Operational capabilities stay opt-in.
            $table->boolean('view_stock')->default(true);
            $table->boolean('view_employee_stock_holders')->default(true);
            $table->boolean('adjust_warehouse_stock')->default(false);
            $table->boolean('consume_other_employees_stock')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_stock_visibility_grants', function (Blueprint $table): void {
            $table->dropColumn([
                'view_stock',
                'view_employee_stock_holders',
                'adjust_warehouse_stock',
                'consume_other_employees_stock',
            ]);
        });
    }
};
