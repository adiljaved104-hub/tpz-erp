<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_fulfillments', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->uuid('movement_group')->unique();
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('fulfilled_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('fulfilled_at')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('order_fulfillments') && DB::table('order_fulfillments')->exists()) {
            throw new RuntimeException('Rollback refused: Order Fulfilments contain immutable history.');
        }

        Schema::dropIfExists('order_fulfillments');
    }
};
