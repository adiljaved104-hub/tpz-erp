<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_profiles', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('company_name_en');
            $table->string('company_name_ar')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('trn', 50)->nullable();
            $table->string('trade_license_number', 100)->nullable();
            $table->string('ded_registration_number', 100)->nullable();
            $table->text('address_en')->nullable();
            $table->text('address_ar')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('mobile', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('country', 100)->nullable();
            $table->string('emirate', 100)->nullable();
            $table->text('legal_statement_en')->nullable();
            $table->text('legal_statement_ar')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_profiles');
    }
};
