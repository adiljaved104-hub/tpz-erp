<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('accounting_obligations', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 48);
            $table->unsignedBigInteger('source_id');
            $table->string('reference', 120);
            $table->unsignedInteger('quantity')->default(0);
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('status', 24)->default('pending');
            $table->json('metadata')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['source_type', 'source_id'], 'acct_obligation_source_uq');
            $table->index(['status', 'created_at'], 'acct_obligation_status_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('accounting_obligations') && Schema::getConnection()->table('accounting_obligations')->exists()) {
            throw new RuntimeException('Rollback refused: accounting obligations contain history.');
        }
        Schema::dropIfExists('accounting_obligations');
    }
};
