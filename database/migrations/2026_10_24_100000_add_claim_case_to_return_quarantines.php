<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_return_quarantines', function (Blueprint $table): void {
            $table->string('claim_case_reference', 32)->nullable()->unique('crq_claim_case_ref_unique');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Return exception claim-case references are permanent audit history.');
    }
};
