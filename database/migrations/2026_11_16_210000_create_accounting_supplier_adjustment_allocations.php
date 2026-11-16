<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_supplier_adjustment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('adjustment_id')->constrained('accounting_supplier_adjustments')->restrictOnDelete();
            $table->foreignId('bill_id')->constrained('accounting_supplier_bills')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestamps();
            $table->unique(['adjustment_id', 'bill_id'], 'accounting_supplier_adjustment_allocations_uq');
            $table->index(['bill_id', 'adjustment_id'], 'accounting_supplier_adjustment_allocations_bill_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_supplier_adjustment_allocations');
    }
};
