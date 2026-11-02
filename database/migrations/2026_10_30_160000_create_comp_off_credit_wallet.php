<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_work_schedule_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_schedule_id')->constrained('work_schedules')->restrictOnDelete();
            $table->date('effective_from')->unique();
            $table->string('reason');
            $table->timestamps();
        });

        Schema::create('comp_off_sunday_duties', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 25)->unique();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('duty_date');
            $table->time('shift_start');
            $table->time('shift_end');
            $table->string('status', 20);
            $table->foreignId('authorized_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('employee_attendance_id')->nullable()->constrained('employee_attendances')->restrictOnDelete();
            $table->unsignedSmallInteger('verified_minutes')->nullable();
            $table->text('reason');
            $table->text('decision_reason')->nullable();
            $table->timestamp('authorized_at');
            $table->timestamp('decided_at')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->timestamps();
            $table->unique(['employee_id', 'duty_date'], 'comp_off_duty_employee_date_uq');
            $table->index(['status', 'duty_date']);
        });

        Schema::create('comp_off_credit_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 25)->unique();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->unsignedSmallInteger('credits_required');
            $table->string('status', 20);
            $table->text('reason');
            $table->foreignId('submitted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('decision_reason')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->timestamps();
            $table->index(['employee_id', 'status', 'from_date'], 'comp_off_request_employee_status_idx');
        });

        Schema::create('comp_off_credit_request_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('comp_off_credit_request_id')->constrained('comp_off_credit_requests', indexName: 'cocrd_request_fk')->restrictOnDelete();
            $table->date('off_date');
            $table->foreignId('work_schedule_id')->nullable()->constrained('work_schedules')->restrictOnDelete();
            $table->string('classification', 20);
            $table->unsignedTinyInteger('credits_required');
            $table->timestamps();
            $table->unique(['comp_off_credit_request_id', 'off_date'], 'cocrd_request_date_uq');
            $table->index('off_date', 'cocrd_date_idx');
        });

        Schema::create('comp_off_wallet_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 25)->unique();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('comp_off_sunday_duty_id')->nullable()->constrained('comp_off_sunday_duties', indexName: 'cowe_duty_fk')->restrictOnDelete();
            $table->foreignId('comp_off_credit_request_id')->nullable()->constrained('comp_off_credit_requests', indexName: 'cowe_request_fk')->restrictOnDelete();
            $table->string('kind', 24);
            $table->smallInteger('earned_delta')->default(0);
            $table->smallInteger('reserved_delta')->default(0);
            $table->smallInteger('used_delta')->default(0);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('operation_key', 100)->unique();
            $table->text('reason');
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['employee_id', 'id'], 'cowe_employee_order_idx');
        });

        Schema::create('comp_off_approval_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('comp_off_sunday_duty_id')->nullable()->constrained('comp_off_sunday_duties', indexName: 'coae_duty_fk')->restrictOnDelete();
            $table->foreignId('comp_off_credit_request_id')->nullable()->constrained('comp_off_credit_requests', indexName: 'coae_request_fk')->restrictOnDelete();
            $table->string('action', 30);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();
        });

        // A prospective company default never rewrites PK_OFFICE, its assignments, or past attendance.
        $effectiveFrom = max('2026-11-01', now('Asia/Karachi')->addDay()->toDateString());
        $now = now();
        $policyId = DB::table('attendance_policies')->where('status', true)
            ->where('effective_from', '<=', $effectiveFrom)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $effectiveFrom))
            ->orderByDesc('effective_from')->value('id');
        $scheduleId = DB::table('work_schedules')->insertGetId([
            'code' => 'PK_STD_2026_11', 'name' => 'Pakistan Standard — Mon–Sat',
            'timezone' => 'Asia/Karachi', 'schedule_type' => 'standard', 'cycle_length_weeks' => 1,
            'grace_minutes' => 5, 'attendance_policy_id' => $policyId,
            'status' => true, 'created_by_user_id' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach (range(0, 6) as $weekday) {
            $working = $weekday !== 0;
            DB::table('work_schedule_days')->insert([
                'work_schedule_id' => $scheduleId, 'cycle_week' => 1, 'weekday' => $weekday,
                'is_working_day' => $working,
                'expected_start_time' => $working ? '09:00:00' : null,
                'expected_end_time' => $weekday === 6 ? '16:00:00' : ($working ? '17:00:00' : null),
                'break_start_time' => null, 'break_end_time' => null,
                'earns_compensatory_off' => false, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        DB::table('company_work_schedule_versions')->insert([
            'work_schedule_id' => $scheduleId, 'effective_from' => $effectiveFrom,
            'reason' => 'Prospective Pakistan standard schedule; individual and team assignments keep precedence.',
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        throw new RuntimeException('Comp-Off wallet and schedule history is forward-only; rollback requires a separate approved data plan.');
    }
};
