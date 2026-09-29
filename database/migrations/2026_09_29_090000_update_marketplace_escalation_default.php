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
            $table->unsignedInteger('escalation_threshold_minutes')->default(120)->change();
        });

        DB::table('marketplace_monitoring_settings')
            ->where('id', 1)
            ->where('escalation_threshold_minutes', 1440)
            ->whereNull('updated_by_user_id')
            ->update(['escalation_threshold_minutes' => 120]);
    }

    public function down(): void
    {
        Schema::table('marketplace_monitoring_settings', function (Blueprint $table): void {
            $table->unsignedInteger('escalation_threshold_minutes')->default(1440)->change();
        });

        DB::table('marketplace_monitoring_settings')
            ->where('id', 1)
            ->where('escalation_threshold_minutes', 120)
            ->whereNull('updated_by_user_id')
            ->update(['escalation_threshold_minutes' => 1440]);
    }
};
