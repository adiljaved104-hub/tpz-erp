<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_invoices', function (Blueprint $table): void {
            $table->string('verification_token', 64)->nullable()->after('idempotency_key');
        });

        DB::table('tax_invoices')
            ->select('id')
            ->orderBy('id')
            ->eachById(function (object $invoice): void {
                DB::table('tax_invoices')
                    ->where('id', $invoice->id)
                    ->update(['verification_token' => bin2hex(random_bytes(32))]);
            });

        Schema::table('tax_invoices', function (Blueprint $table): void {
            $table->unique('verification_token', 'tax_invoices_verification_token_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tax_invoices', function (Blueprint $table): void {
            $table->dropUnique('tax_invoices_verification_token_unique');
            $table->dropColumn('verification_token');
        });
    }
};
