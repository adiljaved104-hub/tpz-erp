<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->timestamp('joining_date_verified_at')->nullable();
            $table->foreignId('joining_date_verified_by_user_id')->nullable()
                ->constrained('users', 'id', 'employees_joining_verified_by_fk')->restrictOnDelete();
        });

        Schema::table('leave_policies', function (Blueprint $table): void {
            $table->string('leave_year_basis', 30)->default('calendar_year');
            $table->boolean('monthly_accrual_enabled')->default(false);
            $table->boolean('carry_forward_enabled')->default(false);
            $table->decimal('maximum_carry_forward_days', 6, 2)->default(0);
            $table->unsignedSmallInteger('carry_forward_expiry_months')->nullable();
            $table->text('policy_description')->nullable();
            $table->text('policy_change_reason')->nullable();
            $table->string('cycle_transition_decision', 40)->nullable();
        });

        Schema::create('employee_joining_date_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('previous_joining_date')->nullable();
            $table->date('corrected_joining_date');
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->json('affected_leave_periods')->nullable();
            $table->timestamp('occurred_at');
            $table->string('action', 24)->default('corrected');
            $table->uuid('idempotency_key')->unique('emp_join_audit_idem_uq');
            $table->index(['employee_id', 'occurred_at'], 'emp_join_audit_employee_date_idx');
        });

        Schema::create('leave_policy_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('leave_policy_id')->constrained('leave_policies')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('event_type', 32);
            $table->date('effective_date');
            $table->json('previous_values')->nullable();
            $table->json('new_values');
            $table->text('reason');
            $table->timestamp('occurred_at');
            $table->uuid('idempotency_key')->unique('leave_policy_event_idem_uq');
            $table->index(['leave_policy_id', 'occurred_at'], 'leave_policy_event_policy_date_idx');
        });

        Schema::create('historical_leave_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('batch_uuid')->unique('hist_leave_batch_uuid_uq');
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('original_filename', 255);
            $table->char('file_sha256', 64);
            $table->string('status', 24)->default('previewed');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->json('preview_summary')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['uploaded_by_user_id', 'created_at'], 'hist_leave_batch_actor_date_idx');
        });

        Schema::create('historical_leave_records', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 32)->unique('hist_leave_reference_uq');
            $table->uuid('record_group_uuid');
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $table->foreignId('leave_policy_id')->nullable()->constrained('leave_policies')->restrictOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->date('leave_period_start');
            $table->date('leave_period_end');
            $table->decimal('chargeable_days', 6, 2);
            $table->string('calculation_method', 24);
            $table->text('manual_override_reason')->nullable();
            $table->string('approval_status', 20);
            $table->text('reason');
            $table->string('original_reference', 255)->nullable();
            $table->string('historical_source', 120)->nullable();
            $table->text('remarks')->nullable();
            $table->string('evidence_path', 500)->nullable();
            $table->string('source', 16);
            $table->foreignId('import_batch_id')->nullable()->constrained('historical_leave_import_batches')->restrictOnDelete();
            $table->unsignedInteger('source_row_number')->nullable();
            $table->date('original_approved_at')->nullable();
            $table->string('original_approver', 255)->nullable();
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('idempotency_key')->unique('hist_leave_record_idem_uq');
            $table->timestamps();
            $table->index(['employee_id', 'approval_status', 'from_date'], 'hist_leave_employee_status_date_idx');
            $table->index(['record_group_uuid'], 'hist_leave_record_group_idx');
            $table->index(['leave_period_start', 'leave_period_end'], 'hist_leave_period_idx');
            $table->index(['import_batch_id', 'source_row_number'], 'hist_leave_batch_row_idx');
        });

        Schema::create('historical_leave_record_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('historical_leave_record_id')->constrained('historical_leave_records')->restrictOnDelete();
            $table->date('leave_date');
            $table->foreignId('work_schedule_id')->nullable()->constrained('work_schedules')->restrictOnDelete();
            $table->foreignId('public_holiday_id')->nullable()->constrained('public_holidays')->restrictOnDelete();
            $table->foreignId('compensatory_off_id')->nullable()->constrained('compensatory_offs')->restrictOnDelete();
            $table->string('classification', 30);
            $table->boolean('counts_as_leave');
            $table->decimal('leave_units', 3, 2)->default(0);
            $table->timestamps();
            $table->unique(['historical_leave_record_id', 'leave_date'], 'hist_leave_days_record_date_uq');
            $table->index(['leave_date', 'classification'], 'hist_leave_days_date_class_idx');
        });

        Schema::create('leave_cycle_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 32)->unique('leave_cycle_adjust_reference_uq');
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('leave_period_start');
            $table->date('leave_period_end');
            $table->string('adjustment_type', 12);
            $table->decimal('days', 6, 2);
            $table->text('reason');
            $table->string('supporting_reference', 255);
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->uuid('idempotency_key')->unique('leave_cycle_adjust_idem_uq');
            $table->timestamps();
            $table->index(['employee_id', 'leave_period_start'], 'leave_cycle_adjust_employee_period_idx');
        });

        Schema::create('historical_leave_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('historical_leave_record_id')->constrained('historical_leave_records')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('event_type', 24);
            $table->json('previous_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('reason');
            $table->string('source', 16);
            $table->timestamp('occurred_at');
            $table->uuid('idempotency_key')->unique('hist_leave_event_idem_uq');
            $table->index(['historical_leave_record_id', 'occurred_at'], 'hist_leave_event_record_date_idx');
        });

    }

    public function down(): void
    {
        throw new RuntimeException('Historical leave, joining-date audit, and leave policy history are permanent. This migration is forward-only.');
    }
};
