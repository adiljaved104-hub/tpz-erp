<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('marketplace_platform_id');
            $table->string('name', 120);
            $table->string('code', 80);
            $table->string('product_condition', 32)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['marketplace_platform_id', 'code'], 'ma_platform_code_uq');
            $table->index(['marketplace_platform_id', 'enabled'], 'ma_platform_enabled_idx');
            $table->foreign('marketplace_platform_id', 'ma_platform_fk')->references('id')->on('marketplace_platforms')->restrictOnDelete();
        });

        Schema::create('marketplace_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('marketplace_account_id');
            $table->string('name', 120);
            $table->string('connection_type', 20);
            $table->string('driver', 80);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('enabled')->default(true);
            $table->string('health_status', 20)->default('unknown');
            $table->string('credential_reference', 191)->nullable();
            $table->json('configuration')->nullable();
            $table->timestamp('last_health_checked_at')->nullable();
            $table->timestamp('last_healthy_at')->nullable();
            $table->timestamps();
            $table->unique(['marketplace_account_id', 'name'], 'mc_account_name_uq');
            $table->index(['marketplace_account_id', 'enabled', 'priority'], 'mc_account_enabled_priority_idx');
            $table->foreign('marketplace_account_id', 'mc_account_fk')->references('id')->on('marketplace_accounts')->cascadeOnDelete();
        });

        Schema::create('marketplace_connection_capabilities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('marketplace_connection_id');
            $table->string('capability', 40);
            $table->boolean('enabled')->default(true);
            $table->unique(['marketplace_connection_id', 'capability'], 'mcc_connection_capability_uq');
            $table->index(['capability', 'enabled'], 'mcc_capability_enabled_idx');
            $table->foreign('marketplace_connection_id', 'mcc_connection_fk')->references('id')->on('marketplace_connections')->cascadeOnDelete();
        });

        Schema::create('marketplace_monitoring_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('monitoring_enabled')->default(true);
            $table->unsignedSmallInteger('monitoring_interval_minutes')->default(15);
            $table->unsignedSmallInteger('employee_reminder_minutes')->default(60);
            $table->boolean('acknowledgement_stops_reminders')->default(true);
            $table->unsignedInteger('escalation_threshold_minutes')->default(1440);
            $table->string('escalation_recipient_strategy', 40)->default('manager_owner_admin');
            $table->json('escalation_channels');
            $table->json('summary_times');
            $table->json('event_channels');
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();
            $table->foreign('updated_by_user_id', 'mms_updated_by_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('marketplace_order_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('marketplace_account_id');
            $table->string('external_order_id', 191);
            $table->timestamp('detected_at');
            $table->string('source', 40);
            $table->timestamps();
            $table->unique(['marketplace_account_id', 'external_order_id'], 'moe_account_external_uq');
            $table->index(['detected_at', 'source'], 'moe_detected_source_idx');
            $table->foreign('marketplace_account_id', 'moe_account_fk')->references('id')->on('marketplace_accounts')->restrictOnDelete();
        });

        Schema::create('marketplace_order_event_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('marketplace_order_event_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('listing_id')->nullable();
            $table->string('external_sku', 191)->nullable();
            $table->string('title', 1000)->nullable();
            $table->string('product_condition', 32)->nullable();
            $table->unsignedInteger('quantity');
            $table->timestamps();
            $table->index(['marketplace_order_event_id', 'product_id'], 'moei_event_product_idx');
            $table->foreign('marketplace_order_event_id', 'moei_event_fk')->references('id')->on('marketplace_order_events')->cascadeOnDelete();
            $table->foreign('product_id', 'moei_product_fk')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('listing_id', 'moei_listing_fk')->references('id')->on('product_marketplace_listings')->restrictOnDelete();
        });

        Schema::table('product_marketplace_listings', function (Blueprint $table): void {
            $table->unsignedBigInteger('marketplace_account_id')->nullable()->after('marketplace_platform_id');
            $table->string('direct_url', 2048)->nullable()->after('listing_title');
            $table->index('marketplace_platform_id', 'pml_platform_idx');
            $table->dropUnique('pml_platform_identifier_uq');
            $table->dropUnique('pml_platform_sku_uq');
            $table->index(['product_id', 'marketplace_account_id'], 'pml_product_account_idx');
            $table->unique(['marketplace_account_id', 'marketplace_identifier'], 'pml_account_identifier_uq');
            $table->unique(['marketplace_account_id', 'listing_sku'], 'pml_account_sku_uq');
            $table->foreign('marketplace_account_id', 'pml_account_fk')->references('id')->on('marketplace_accounts')->restrictOnDelete();
        });

        foreach (DB::table('marketplace_platforms')->orderBy('id')->get(['id', 'name', 'code']) as $platform) {
            $accountId = DB::table('marketplace_accounts')->insertGetId([
                'marketplace_platform_id' => $platform->id,
                'name' => 'Default',
                'code' => 'default',
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('product_marketplace_listings')->where('marketplace_platform_id', $platform->id)->update(['marketplace_account_id' => $accountId]);
        }

        Schema::table('marketplace_monitor_observations', function (Blueprint $table): void {
            $table->unsignedBigInteger('marketplace_account_id')->nullable()->after('listing_id');
            $table->unsignedBigInteger('marketplace_connection_id')->nullable()->after('marketplace_account_id');
            $table->index(['marketplace_account_id', 'observed_at'], 'mmo_account_observed_idx');
            $table->foreign('marketplace_account_id', 'mmo_account_fk')->references('id')->on('marketplace_accounts')->restrictOnDelete();
            $table->foreign('marketplace_connection_id', 'mmo_connection_fk')->references('id')->on('marketplace_connections')->restrictOnDelete();
        });

        Schema::table('marketplace_operation_incidents', function (Blueprint $table): void {
            $table->unsignedBigInteger('marketplace_account_id')->nullable()->after('marketplace_platform_id');
            $table->index(['marketplace_account_id', 'resolved_at'], 'moi_account_resolved_idx');
            $table->foreign('marketplace_account_id', 'moi_account_fk')->references('id')->on('marketplace_accounts')->restrictOnDelete();
        });
        DB::statement('UPDATE marketplace_monitor_observations SET marketplace_account_id = (SELECT marketplace_account_id FROM product_marketplace_listings WHERE product_marketplace_listings.id = marketplace_monitor_observations.listing_id)');
        DB::statement('UPDATE marketplace_operation_incidents SET marketplace_account_id = (SELECT marketplace_account_id FROM product_marketplace_listings WHERE product_marketplace_listings.id = marketplace_operation_incidents.listing_id) WHERE listing_id IS NOT NULL');

        DB::table('marketplace_monitoring_settings')->insert([
            'id' => 1,
            'monitoring_enabled' => true,
            'monitoring_interval_minutes' => 15,
            'employee_reminder_minutes' => 60,
            'acknowledgement_stops_reminders' => true,
            'escalation_threshold_minutes' => 1440,
            'escalation_recipient_strategy' => 'manager_owner_admin',
            'escalation_channels' => json_encode(['in_app', 'email']),
            'summary_times' => json_encode(['09:00', '14:00', '19:00']),
            'event_channels' => json_encode(['in_app', 'email']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (Schema::hasTable('notification_rules')) {
            DB::table('notification_rules')->insertOrIgnore([
                'event_key' => 'marketplace.new_order', 'name' => 'Marketplace New Order', 'category' => 'Marketplace', 'enabled' => true,
                'in_app_enabled' => true, 'email_enabled' => true, 'recipient_strategy' => 'existing_business_routing',
                'configuration' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('marketplace_operation_incidents', function (Blueprint $table): void {
            $table->dropForeign('moi_account_fk');
            $table->dropIndex('moi_account_resolved_idx');
            $table->dropColumn('marketplace_account_id');
        });
        Schema::table('marketplace_monitor_observations', function (Blueprint $table): void {
            $table->dropForeign('mmo_connection_fk');
            $table->dropForeign('mmo_account_fk');
            $table->dropIndex('mmo_account_observed_idx');
            $table->dropColumn(['marketplace_account_id', 'marketplace_connection_id']);
        });
        Schema::dropIfExists('marketplace_order_event_items');
        Schema::dropIfExists('marketplace_order_events');
        Schema::table('product_marketplace_listings', function (Blueprint $table): void {
            $table->dropForeign('pml_account_fk');
            $table->dropIndex('pml_product_account_idx');
            $table->dropUnique('pml_account_identifier_uq');
            $table->dropUnique('pml_account_sku_uq');
            $table->dropColumn(['marketplace_account_id', 'direct_url']);
            $table->unique(['marketplace_platform_id', 'marketplace_identifier'], 'pml_platform_identifier_uq');
            $table->unique(['marketplace_platform_id', 'listing_sku'], 'pml_platform_sku_uq');
        });

        Schema::table('product_marketplace_listings', function (Blueprint $table): void {
            $table->dropIndex('pml_platform_idx');
        });
        Schema::dropIfExists('marketplace_monitoring_settings');
        Schema::dropIfExists('marketplace_connection_capabilities');
        Schema::dropIfExists('marketplace_connections');
        Schema::dropIfExists('marketplace_accounts');
    }
};
