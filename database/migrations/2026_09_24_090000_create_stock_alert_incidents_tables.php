<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_alert_incidents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('incident_key')->unique();
            $table->foreignId('product_inventory_id')->constrained('product_inventories')->restrictOnDelete();
            $table->string('alert_type', 32);
            $table->string('active_key', 191)->nullable()->unique();
            $table->integer('opened_sellable_quantity');
            $table->integer('current_sellable_quantity');
            $table->timestamp('opened_at');
            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['product_inventory_id', 'alert_type', 'resolved_at'], 'stock_alert_incidents_active_lookup');
            $table->index(['resolved_at', 'opened_at'], 'stock_alert_incidents_reminder_lookup');
        });

        Schema::create('stock_alert_incident_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_alert_incident_id')->constrained('stock_alert_incidents')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('recipient_role', 20)->default('primary');
            $table->uuid('initial_notification_id')->nullable();
            $table->timestamp('initial_notified_at')->nullable();
            $table->unsignedSmallInteger('reminder_step')->default(0);
            $table->timestamp('last_reminded_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->unique(['stock_alert_incident_id', 'user_id'], 'stock_alert_incident_recipient_unique');
            $table->index(['user_id', 'acknowledged_at'], 'stock_alert_recipient_ack_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_alert_incident_recipients');
        Schema::dropIfExists('stock_alert_incidents');
    }
};
