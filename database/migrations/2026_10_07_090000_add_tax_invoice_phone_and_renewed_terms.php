<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_invoices', function (Blueprint $table): void {
            $table->string('customer_phone', 40)->nullable();
            $table->string('terms_profile', 20)->nullable();
            $table->text('renewed_terms_en_snapshot')->nullable();
            $table->text('renewed_terms_ar_snapshot')->nullable();
        });

        Schema::table('invoice_settings', function (Blueprint $table): void {
            $table->text('renewed_terms_en')->nullable();
            $table->text('renewed_terms_ar')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_settings', function (Blueprint $table): void {
            $table->dropColumn(['renewed_terms_en', 'renewed_terms_ar']);
        });

        Schema::table('tax_invoices', function (Blueprint $table): void {
            $table->dropColumn(['customer_phone', 'terms_profile', 'renewed_terms_en_snapshot', 'renewed_terms_ar_snapshot']);
        });
    }
};
