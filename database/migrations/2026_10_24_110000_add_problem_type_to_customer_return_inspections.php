<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_return_inspections', function (Blueprint $table): void {
            $table->string('problem_type', 32)->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Recorded Return QC history must not be removed by an automatic rollback.');
    }
};
