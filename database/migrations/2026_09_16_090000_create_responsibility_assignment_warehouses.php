<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('responsibility_assignment_warehouses', function (Blueprint $table): void {
            $table->foreignId('assignment_id')->primary()->constrained('responsibility_assignments')->restrictOnDelete();
            $table->foreignId('warehouse_id');
            $table->foreign('warehouse_id', 'ra_warehouses_warehouse_fk')->references('id')->on('warehouses')->restrictOnDelete();
            $table->index('warehouse_id', 'ra_warehouses_warehouse_idx');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('responsibility_assignment_warehouses')
            && DB::table('responsibility_assignment_warehouses')->exists()) {
            throw new RuntimeException('Rollback refused: Warehouse Responsibility history exists.');
        }

        Schema::dropIfExists('responsibility_assignment_warehouses');
    }
};
