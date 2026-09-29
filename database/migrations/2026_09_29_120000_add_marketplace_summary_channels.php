<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_monitoring_settings', function (Blueprint $table): void {
            $table->json('summary_channels')->nullable()->after('summary_times');
        });

        DB::table('marketplace_monitoring_settings')->update([
            'summary_channels' => json_encode(['email']),
        ]);

        $setting = DB::table('marketplace_monitoring_settings')->where('id', 1)->first();
        if ($setting !== null && $setting->updated_by_user_id === null) {
            $defaultChannels = json_encode(['in_app', 'email', 'push']);
            DB::table('marketplace_monitoring_settings')->where('id', 1)->update([
                'event_channels' => $defaultChannels,
                'escalation_channels' => $defaultChannels,
            ]);
        }

        DB::table('notification_rules')
            ->where('event_key', 'marketplace.daily_summary')
            ->whereNull('updated_by_user_id')
            ->update(['in_app_enabled' => true]);
    }

    public function down(): void
    {
        DB::table('notification_rules')
            ->where('event_key', 'marketplace.daily_summary')
            ->whereNull('updated_by_user_id')
            ->update(['in_app_enabled' => false]);

        Schema::table('marketplace_monitoring_settings', function (Blueprint $table): void {
            $table->dropColumn('summary_channels');
        });
    }
};
