<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('marketplace_platforms', function (Blueprint $table) {
            $table->boolean('customer_return_claims_enabled')->default(false)->index();
            $table->string('claim_program_name')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('marketplace_platforms', function (Blueprint $table) {
            $table->dropIndex(['customer_return_claims_enabled']);
            $table->dropColumn(['customer_return_claims_enabled', 'claim_program_name']);
        });
    }
};
