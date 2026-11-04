<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->restrictOnDelete();
            $table->foreignId('stock_transfer_item_id')->constrained('stock_transfer_items')->restrictOnDelete();
            $table->foreignId('inventory_reservation_id')->constrained('inventory_reservations')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('status', 24)->default('active');
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();
            $table->unique(['stock_transfer_id', 'stock_transfer_item_id', 'status'], 'str_active_item_uq');
            $table->index(['stock_transfer_id', 'status'], 'str_transfer_status_idx');
        });

        Schema::create('stock_transfer_marketplace_losses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->restrictOnDelete();
            $table->foreignId('stock_transfer_item_id')->constrained('stock_transfer_items')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('status', 24)->default('pending');
            $table->string('marketplace_reference')->nullable();
            $table->text('notes')->nullable();
            $table->json('evidence')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('reported_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['stock_transfer_id', 'status'], 'stml_transfer_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_marketplace_losses');
        Schema::dropIfExists('stock_transfer_reservations');
    }
};
