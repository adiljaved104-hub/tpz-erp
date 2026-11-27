<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_settings', function (Blueprint $table): void {
            $table->json('footer_branding')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only migration: preserve invoice footer approval configuration.');
    }
};
