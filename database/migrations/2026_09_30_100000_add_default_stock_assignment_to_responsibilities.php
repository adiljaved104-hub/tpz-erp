<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('responsibility_assignments', function (Blueprint $table): void {
            $table->boolean('assign_stock_by_default')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('responsibility_assignments', function (Blueprint $table): void {
            $table->dropColumn('assign_stock_by_default');
        });
    }
};
