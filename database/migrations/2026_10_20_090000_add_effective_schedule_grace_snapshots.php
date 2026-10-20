<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_schedules', function (Blueprint $table): void {
            $table->unsignedSmallInteger('grace_minutes')->nullable();
        });
        Schema::table('work_schedule_assignments', function (Blueprint $table): void {
            $table->unsignedSmallInteger('grace_minutes_override')->nullable();
        });
        Schema::table('employee_attendances', function (Blueprint $table): void {
            $table->unsignedSmallInteger('grace_minutes')->nullable();
        });

        DB::table('work_schedules')->orderBy('id')->chunkById(200, function ($schedules): void {
            foreach ($schedules as $schedule) {
                $grace = $schedule->attendance_policy_id === null
                    ? null
                    : DB::table('attendance_policies')->where('id', $schedule->attendance_policy_id)->value('grace_minutes');

                if ($grace !== null) {
                    DB::table('work_schedules')->where('id', $schedule->id)->update(['grace_minutes' => (int) $grace]);
                }
            }
        });

        DB::table('employee_attendances')->whereNull('grace_minutes')->orderBy('id')->chunkById(500, function ($attendances): void {
            foreach ($attendances as $attendance) {
                if ($attendance->attendance_policy_id === null) {
                    continue;
                }

                $grace = DB::table('attendance_policies')->where('id', $attendance->attendance_policy_id)->value('grace_minutes');
                if ($grace !== null) {
                    DB::table('employee_attendances')->where('id', $attendance->id)->update(['grace_minutes' => (int) $grace]);
                }
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Schedule grace defaults and Attendance grace snapshots are historical records and cannot be rolled back safely.');
    }
};
