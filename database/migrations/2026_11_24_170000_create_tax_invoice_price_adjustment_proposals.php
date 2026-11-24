<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_invoice_price_adjustment_proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_invoice_id')->constrained('tax_invoices')->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('pending_review');
            $table->text('reason');
            $table->json('original_line_snapshot');
            $table->json('proposed_line_snapshot');
            $table->json('adjustment_documents_preview');
            $table->decimal('old_gross', 14, 2);
            $table->decimal('new_gross', 14, 2);
            $table->decimal('gross_difference', 14, 2);
            $table->timestamps();
            $table->index(['tax_invoice_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_invoice_price_adjustment_proposals');
    }
};
