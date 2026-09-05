<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_platforms', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('normalized_name')->unique();
            $table->string('code', 100)->unique();
            $table->boolean('status')->default(true)->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('marketplace_platforms') && DB::table('marketplace_platforms')->exists()) {
            throw new RuntimeException('Rollback refused: Marketplace Platforms contain operational history.');
        }

        Schema::dropIfExists('marketplace_platforms');
    }
};
