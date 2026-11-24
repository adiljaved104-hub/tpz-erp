<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_invoice_price_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('original_invoice_id')->unique()->constrained('tax_invoices')->restrictOnDelete();
            $table->foreignId('replacement_invoice_id')->unique()->constrained('tax_invoices')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_invoice_price_corrections');
    }
};
