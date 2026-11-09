<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A pending marketplace loss has neither investigator nor claim submitter.
        // The original lifecycle extension added both FK columns as NOT NULL,
        // preventing legitimate pending-loss creation on strict MySQL.
        Schema::table('stock_transfer_marketplace_losses', function (Blueprint $table): void {
            $table->foreignId('investigating_by_user_id')->nullable()->change();
            $table->foreignId('claim_submitted_by_user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Forward-only correction. Pending loss rows legitimately contain null
        // actor IDs; reverting to NOT NULL would break or destroy their history.
    }
};
