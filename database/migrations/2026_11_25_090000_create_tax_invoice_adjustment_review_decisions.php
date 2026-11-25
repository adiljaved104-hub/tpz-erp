<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_invoice_adjustment_review_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('proposal_id')->unique();
            $table->foreign('proposal_id', 'ti_review_proposal_fk')->references('id')->on('tax_invoice_price_adjustment_proposals')->restrictOnDelete();
            $table->foreignId('reviewed_by_user_id');
            $table->foreign('reviewed_by_user_id', 'ti_review_actor_fk')->references('id')->on('users')->restrictOnDelete();
            $table->string('decision', 20);
            $table->text('reason');
            $table->timestamp('decided_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_invoice_adjustment_review_decisions');
    }
};
