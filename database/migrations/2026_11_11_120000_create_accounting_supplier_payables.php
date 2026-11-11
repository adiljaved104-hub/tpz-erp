<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_supplier_bills', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('accounting_book_id')->constrained('accounting_books')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('purchase_id')->nullable()->constrained('purchases')->restrictOnDelete();
            $table->string('bill_number', 80)->nullable();
            $table->date('bill_date');
            $table->date('due_date')->nullable();
            $table->char('currency', 3)->default('AED');
            $table->decimal('subtotal', 18, 2);
            $table->decimal('vat_total', 18, 2)->default(0);
            $table->decimal('grand_total', 18, 2);
            $table->string('status', 20)->default('draft');
            $table->string('idempotency_key', 100);
            $table->string('source_type', 120)->nullable();
            $table->string('source_key', 190)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['accounting_book_id', 'idempotency_key'], 'accounting_supplier_bills_book_idempotency_uq');
            $table->unique(['accounting_book_id', 'supplier_id', 'bill_number'], 'accounting_supplier_bills_supplier_number_uq');
            $table->index(['accounting_book_id', 'supplier_id', 'due_date', 'status'], 'accounting_supplier_bills_ageing_idx');
        });

        Schema::create('accounting_supplier_bill_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bill_id')->constrained('accounting_supplier_bills')->cascadeOnDelete();
            $table->foreignId('purchase_item_id')->nullable()->constrained('purchase_items')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('description', 250);
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_cost', 18, 2);
            $table->decimal('net_amount', 18, 2);
            $table->decimal('vat_rate', 8, 4)->default(0);
            $table->decimal('vat_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2);
            $table->unsignedInteger('line_number');
            $table->timestamps();
            $table->unique(['bill_id', 'line_number'], 'accounting_supplier_bill_lines_bill_line_uq');
        });

        Schema::create('accounting_supplier_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('accounting_book_id')->constrained('accounting_books')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->date('payment_date');
            $table->char('currency', 3)->default('AED');
            $table->decimal('amount', 18, 2);
            $table->string('reference', 100);
            $table->string('status', 20)->default('draft');
            $table->string('idempotency_key', 100);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['accounting_book_id', 'idempotency_key'], 'accounting_supplier_payments_book_idempotency_uq');
            $table->index(['accounting_book_id', 'supplier_id', 'payment_date'], 'accounting_supplier_payments_supplier_date_idx');
        });

        Schema::create('accounting_supplier_payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained('accounting_supplier_payments')->cascadeOnDelete();
            $table->foreignId('bill_id')->constrained('accounting_supplier_bills')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestamps();
            $table->unique(['payment_id', 'bill_id'], 'accounting_supplier_payment_allocations_payment_bill_uq');
        });

        Schema::create('accounting_supplier_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('accounting_book_id')->constrained('accounting_books')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('bill_id')->nullable()->constrained('accounting_supplier_bills')->restrictOnDelete();
            $table->string('kind', 12);
            $table->date('adjustment_date');
            $table->char('currency', 3)->default('AED');
            $table->decimal('amount', 18, 2);
            $table->string('reference', 100);
            $table->string('status', 20)->default('draft');
            $table->string('idempotency_key', 100);
            $table->text('reason');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['accounting_book_id', 'idempotency_key'], 'accounting_supplier_adjustments_book_idempotency_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_supplier_adjustments');
        Schema::dropIfExists('accounting_supplier_payment_allocations');
        Schema::dropIfExists('accounting_supplier_payments');
        Schema::dropIfExists('accounting_supplier_bill_lines');
        Schema::dropIfExists('accounting_supplier_bills');
    }
};
