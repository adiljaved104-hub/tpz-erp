<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_marketplace_listings', function (Blueprint $table): void {
            $table->boolean('monitor_enabled')->default(true)->after('listing_title');
            $table->string('monitor_source', 40)->default('amazon_api')->after('monitor_enabled');
            $table->string('featured_offer_state', 10)->default('unknown')->after('monitor_source');
            $table->string('listing_active_state', 10)->default('unknown')->after('featured_offer_state');
            $table->timestamp('last_checked_at')->nullable()->after('listing_active_state');
            $table->timestamp('last_successful_observation_at')->nullable()->after('last_checked_at');
            $table->string('last_check_status', 40)->nullable()->after('last_successful_observation_at');
            $table->string('last_check_error', 500)->nullable()->after('last_check_status');
            $table->index(['monitor_enabled', 'last_checked_at'], 'pml_monitor_due_idx');
        });

        Schema::create('marketplace_monitor_observations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('listing_id');
            $table->string('source', 40);
            $table->string('listing_active_state', 10);
            $table->string('featured_offer_state', 10);
            $table->timestamp('observed_at');
            $table->string('source_status', 40)->nullable();
            $table->string('safe_error', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['listing_id', 'observed_at'], 'mmo_listing_observed_idx');
            $table->foreign('listing_id', 'mmo_listing_fk')->references('id')->on('product_marketplace_listings')->restrictOnDelete();
        });

        Schema::create('marketplace_operation_incidents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('incident_key')->unique('moi_incident_key_uq');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('listing_id')->nullable();
            $table->unsignedBigInteger('marketplace_platform_id');
            $table->unsignedBigInteger('responsibility_assignment_id')->nullable();
            $table->unsignedBigInteger('responsible_employee_id')->nullable();
            $table->unsignedBigInteger('responsible_team_id')->nullable();
            $table->string('incident_type', 32);
            $table->string('active_key', 191)->nullable()->unique('moi_active_key_uq');
            $table->unsignedInteger('exposed_listing_count')->nullable();
            $table->unsignedInteger('usable_quantity')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamp('regained_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['incident_type', 'resolved_at'], 'moi_type_resolved_idx');
            $table->index(['responsible_employee_id', 'resolved_at'], 'moi_employee_resolved_idx');
            $table->foreign('product_id', 'moi_product_fk')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('listing_id', 'moi_listing_fk')->references('id')->on('product_marketplace_listings')->restrictOnDelete();
            $table->foreign('marketplace_platform_id', 'moi_platform_fk')->references('id')->on('marketplace_platforms')->restrictOnDelete();
            $table->foreign('responsibility_assignment_id', 'moi_responsibility_fk')->references('id')->on('responsibility_assignments')->restrictOnDelete();
            $table->foreign('responsible_employee_id', 'moi_employee_fk')->references('id')->on('employees')->restrictOnDelete();
            $table->foreign('responsible_team_id', 'moi_team_fk')->references('id')->on('teams')->restrictOnDelete();
        });

        Schema::create('marketplace_operation_incident_recipients', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('incident_id');
            $table->unsignedBigInteger('user_id');
            $table->string('recipient_role', 20)->default('primary');
            $table->uuid('initial_notification_id')->nullable();
            $table->timestamp('initial_notified_at')->nullable();
            $table->unsignedSmallInteger('reminder_step')->default(0);
            $table->timestamp('last_reminded_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->unique(['incident_id', 'user_id'], 'moir_incident_user_uq');
            $table->index(['user_id', 'acknowledged_at'], 'moir_user_ack_idx');
            $table->foreign('incident_id', 'moir_incident_fk')->references('id')->on('marketplace_operation_incidents')->cascadeOnDelete();
            $table->foreign('user_id', 'moir_user_fk')->references('id')->on('users')->restrictOnDelete();
        });

        if (Schema::hasTable('notification_rules')) {
            $now = now();
            DB::table('notification_rules')->insertOrIgnore([
                ['event_key' => 'marketplace.featured_offer_lost', 'name' => 'Featured Offer Lost', 'category' => 'Marketplace', 'enabled' => true, 'in_app_enabled' => true, 'email_enabled' => true, 'recipient_strategy' => 'existing_business_routing', 'threshold_value' => null, 'threshold_unit' => null, 'configuration' => json_encode(['reminder_hours' => [2, 24], 'escalation_hours' => 24]), 'created_at' => $now, 'updated_at' => $now],
                ['event_key' => 'marketplace.stock_exposure', 'name' => 'Marketplace Stock Exposure', 'category' => 'Marketplace', 'enabled' => true, 'in_app_enabled' => true, 'email_enabled' => true, 'recipient_strategy' => 'existing_business_routing', 'threshold_value' => null, 'threshold_unit' => null, 'configuration' => json_encode(['reminder_hours' => [2, 24], 'escalation_hours' => 24]), 'created_at' => $now, 'updated_at' => $now],
                ['event_key' => 'marketplace.daily_summary', 'name' => 'Marketplace Operations Daily Summary', 'category' => 'Marketplace', 'enabled' => true, 'in_app_enabled' => false, 'email_enabled' => true, 'recipient_strategy' => 'owner_admin_fallback', 'threshold_value' => null, 'threshold_unit' => null, 'configuration' => json_encode([]), 'created_at' => $now, 'updated_at' => $now],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_operation_incident_recipients');
        Schema::dropIfExists('marketplace_operation_incidents');
        Schema::dropIfExists('marketplace_monitor_observations');
        Schema::table('product_marketplace_listings', function (Blueprint $table): void {
            $table->dropIndex('pml_monitor_due_idx');
            $table->dropColumn(['monitor_enabled', 'monitor_source', 'featured_offer_state', 'listing_active_state', 'last_checked_at', 'last_successful_observation_at', 'last_check_status', 'last_check_error']);
        });
    }
};
