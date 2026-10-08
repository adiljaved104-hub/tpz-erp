<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_credential_vaults', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 191)->unique();
            $table->longText('encrypted_payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_credential_vaults');
    }
};
