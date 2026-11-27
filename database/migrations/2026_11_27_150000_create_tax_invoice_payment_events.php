<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_invoice_payment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_invoice_id')->constrained('tax_invoices')->restrictOnDelete();
            $table->foreignId('changed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20);
            $table->decimal('payment_received_aed', 14, 2);
            $table->decimal('balance_due_aed', 14, 2);
            $table->string('evidence_reference', 190)->nullable();
            $table->text('reason')->nullable();
            $table->string('source', 30);
            $table->timestamps();
            $table->index(['tax_invoice_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_invoice_payment_events');
    }
};
