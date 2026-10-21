<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('historical_leave_import_batches')->select('file_sha256')->groupBy('file_sha256')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Duplicate historical leave import hashes exist; resolve them before adding the idempotency index. No records were changed.');
        }
        if (DB::table('leave_cycle_adjustments')->select('employee_id', 'leave_period_start', 'supporting_reference')
            ->groupBy('employee_id', 'leave_period_start', 'supporting_reference')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Duplicate supporting references exist for a leave cycle; review them before adding the adjustment idempotency index. No records were changed.');
        }

        Schema::table('historical_leave_import_batches', function (Blueprint $table): void {
            $table->unique('file_sha256', 'hist_leave_batch_file_sha256_uq');
        });
        Schema::table('leave_cycle_adjustments', function (Blueprint $table): void {
            $table->unique(['employee_id', 'leave_period_start', 'supporting_reference'], 'leave_cycle_adjust_employee_ref_uq');
        });
    }

    public function down(): void
    {
        Schema::table('leave_cycle_adjustments', function (Blueprint $table): void {
            $table->dropUnique('leave_cycle_adjust_employee_ref_uq');
        });
        Schema::table('historical_leave_import_batches', function (Blueprint $table): void {
            $table->dropUnique('hist_leave_batch_file_sha256_uq');
        });
    }
};
