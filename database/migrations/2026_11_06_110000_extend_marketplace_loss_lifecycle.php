<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stock_transfer_marketplace_losses', function (Blueprint $table): void {
            $table->string('claim_reference')->nullable()->after('marketplace_reference');
            $table->decimal('claim_amount', 15, 2)->nullable()->after('claim_reference');
            $table->decimal('reimbursed_amount', 15, 2)->nullable()->after('claim_amount');
            $table->timestamp('investigating_at')->nullable()->after('resolved_at');
            $table->timestamp('claim_submitted_at')->nullable()->after('investigating_at');
            $table->timestamp('closed_at')->nullable()->after('claim_submitted_at');
            $table->foreignId('investigating_by_user_id')->nullable()->after('reported_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('claim_submitted_by_user_id')->nullable()->after('investigating_by_user_id')->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfer_marketplace_losses', function (Blueprint $table): void {
            $table->dropForeign(['investigating_by_user_id']);
            $table->dropForeign(['claim_submitted_by_user_id']);
            $table->dropColumn(['claim_reference', 'claim_amount', 'reimbursed_amount', 'investigating_at', 'claim_submitted_at', 'closed_at', 'investigating_by_user_id', 'claim_submitted_by_user_id']);
        });
    }
};
