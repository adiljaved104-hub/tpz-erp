<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qc_exception_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('duration_mode', 20)->default('disabled');
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();
            $table->foreign('updated_by_user_id', 'fk_qc_exception_settings_user')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qc_exception_settings');
    }
};
