<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_invoice_adjustment_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('proposal_id');
            $table->foreign('proposal_id', 'ti_adj_doc_proposal_fk')->references('id')->on('tax_invoice_price_adjustment_proposals')->restrictOnDelete();
            $table->foreignId('original_invoice_id');
            $table->foreign('original_invoice_id', 'ti_adj_doc_invoice_fk')->references('id')->on('tax_invoices')->restrictOnDelete();
            $table->foreignId('accounting_book_id');
            $table->foreign('accounting_book_id', 'ti_adj_doc_book_fk')->references('id')->on('accounting_books')->restrictOnDelete();
            $table->foreignId('original_accounting_journal_id');
            $table->foreign('original_accounting_journal_id', 'ti_adj_doc_orig_journal_fk')->references('id')->on('accounting_journals')->restrictOnDelete();
            $table->foreignId('adjustment_accounting_journal_id')->unique();
            $table->foreign('adjustment_accounting_journal_id', 'ti_adj_doc_adj_journal_fk')->references('id')->on('accounting_journals')->restrictOnDelete();
            $table->foreignId('issued_by_user_id');
            $table->foreign('issued_by_user_id', 'ti_adj_doc_issuer_fk')->references('id')->on('users')->restrictOnDelete();
            $table->string('document_number', 80)->unique();
            $table->string('kind', 30);
            $table->date('issued_on');
            $table->string('status', 20)->default('issued');
            $table->decimal('net_amount', 14, 2);
            $table->decimal('vat_amount', 14, 2);
            $table->decimal('gross_amount', 14, 2);
            $table->decimal('original_invoice_net', 14, 2);
            $table->decimal('corrected_invoice_net', 14, 2);
            $table->json('seller_snapshot');
            $table->json('customer_snapshot');
            $table->json('lines_snapshot');
            $table->text('explanation');
            $table->string('financial_reconciliation_reference', 190);
            $table->timestamp('issued_at');
            $table->timestamps();
            $table->unique(['proposal_id', 'kind'], 'ti_adj_doc_proposal_kind_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_invoice_adjustment_documents');
    }
};
