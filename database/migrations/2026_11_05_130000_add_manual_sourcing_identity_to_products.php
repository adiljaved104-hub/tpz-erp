<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('manual_sourcing_identity', 64)->nullable()->unique('products_manual_source_identity_uq');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique('products_manual_source_identity_uq');
            $table->dropColumn('manual_sourcing_identity');
        });
    }
};
