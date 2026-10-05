<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serialized_units', function (Blueprint $table): void {
            $table->id();
            $table->string('unit_code', 40)->unique();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_inventory_id')->nullable()->constrained('product_inventories')->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->string('manufacturer_serial', 160)->unique();
            $table->string('service_tag', 160)->nullable()->index();
            $table->string('imei', 32)->nullable()->unique();
            $table->string('device_type', 32)->index();
            $table->string('status', 32)->default('received')->index();
            $table->json('initial_configuration');
            $table->json('current_configuration');
            $table->foreignId('received_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['product_id', 'status'], 'serialized_units_product_status_idx');
            $table->index(['warehouse_id', 'status'], 'serialized_units_warehouse_status_idx');
        });

        Schema::create('serialized_unit_configuration_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('serialized_unit_id')->constrained('serialized_units')->restrictOnDelete();
            $table->string('event_type', 32);
            $table->json('before_configuration')->nullable();
            $table->json('after_configuration');
            $table->string('source_type', 120)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['serialized_unit_id', 'occurred_at'], 'serialized_unit_configuration_events_unit_time_idx');
            $table->index(['source_type', 'source_id'], 'serialized_unit_configuration_events_source_idx');
        });

        Schema::create('quality_control_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64);
            $table->string('name', 160);
            $table->string('device_type', 32)->index();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('active')->default(true)->index();
            $table->text('description')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['code', 'version'], 'quality_control_templates_code_version_uq');
            $table->index(['device_type', 'active'], 'quality_control_templates_device_active_idx');
        });

        Schema::create('quality_control_template_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quality_control_template_id')->constrained('quality_control_templates')->restrictOnDelete();
            $table->string('section_key', 64);
            $table->string('section_label', 160);
            $table->string('check_code', 64);
            $table->string('label', 160);
            $table->string('input_type', 32);
            $table->boolean('required')->default(true);
            $table->boolean('critical')->default(false);
            $table->boolean('allow_na')->default(false);
            $table->boolean('evidence_on_fail')->default(false);
            $table->json('validation_rules')->nullable();
            $table->json('applicability_rules')->nullable();
            $table->text('help_text')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();

            $table->unique(
                ['quality_control_template_id', 'check_code'],
                'quality_control_template_checks_template_code_uq'
            );
            $table->index(
                ['quality_control_template_id', 'section_key', 'sequence'],
                'quality_control_template_checks_section_sequence_idx'
            );
        });

        Schema::create('quality_control_inspections', function (Blueprint $table): void {
            $table->id();
            $table->string('qc_number', 40)->unique();
            $table->foreignId('serialized_unit_id')->constrained('serialized_units')->restrictOnDelete();
            $table->foreignId('quality_control_template_id')->constrained('quality_control_templates')->restrictOnDelete();
            $table->unsignedInteger('template_version');
            $table->string('status', 32)->default('draft')->index();
            $table->string('cosmetic_grade', 32)->nullable()->index();
            $table->json('configuration_snapshot');
            $table->json('order_requirement_snapshot')->nullable();
            $table->string('verification_token', 64)->nullable()->unique();
            $table->unsignedInteger('report_revision')->default(1);
            $table->foreignId('started_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['serialized_unit_id', 'status'], 'quality_control_inspections_unit_status_idx');
            $table->index(['quality_control_template_id', 'template_version'], 'quality_control_inspections_template_version_idx');
        });

        Schema::create('quality_control_inspection_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quality_control_inspection_id')->constrained('quality_control_inspections')->restrictOnDelete();
            $table->string('check_code', 64);
            $table->string('section_key', 64);
            $table->string('label_snapshot', 160);
            $table->string('input_type_snapshot', 32);
            $table->boolean('required_snapshot')->default(true);
            $table->boolean('critical_snapshot')->default(false);
            $table->boolean('allow_na_snapshot')->default(false);
            $table->string('outcome', 16)->nullable()->index();
            $table->decimal('numeric_value', 14, 4)->nullable();
            $table->text('text_value')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('evidence_required')->default(false);
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();

            $table->unique(
                ['quality_control_inspection_id', 'check_code'],
                'quality_control_inspection_results_inspection_code_uq'
            );
            $table->index(
                ['quality_control_inspection_id', 'section_key', 'sequence'],
                'quality_control_inspection_results_section_sequence_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_control_inspection_results');
        Schema::dropIfExists('quality_control_inspections');
        Schema::dropIfExists('quality_control_template_checks');
        Schema::dropIfExists('quality_control_templates');
        Schema::dropIfExists('serialized_unit_configuration_events');
        Schema::dropIfExists('serialized_units');
    }
};
