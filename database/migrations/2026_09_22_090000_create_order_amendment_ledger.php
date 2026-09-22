<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_settings', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedTinyInteger('amendment_window_hours')->default(1);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('order_amendments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('amended_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestamp('window_started_at');
            $table->timestamp('window_expired_at');
            $table->boolean('after_window_override')->default(false);
            $table->uuid('idempotency_key')->unique();
            $table->string('request_hash', 64);
            $table->timestamps();
            $table->index(['order_id', 'created_at']);
        });

        Schema::create('order_amendment_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_amendment_id')->constrained('order_amendments')->restrictOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->restrictOnDelete();
            $table->string('field', 40);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamps();
            $table->index(['order_item_id', 'field']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('order_amendment_lines') && DB::table('order_amendment_lines')->exists()
            || Schema::hasTable('order_amendments') && DB::table('order_amendments')->exists()
            || Schema::hasTable('order_settings') && DB::table('order_settings')->exists()) {
            throw new RuntimeException('Rollback refused: Order amendment settings/history contains records.');
        }

        Schema::dropIfExists('order_amendment_lines');
        Schema::dropIfExists('order_amendments');
        Schema::dropIfExists('order_settings');
    }
};
