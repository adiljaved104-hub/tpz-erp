<?php

namespace Tests\Feature\QualityControl;

use App\Enums\QualityControlCheckInputType;
use App\Enums\QualityControlCosmeticGrade;
use App\Enums\QualityControlDeviceType;
use App\Enums\QualityControlInspectionStatus;
use App\Enums\QualityControlOutcome;
use App\Enums\SerializedUnitStatus;
use App\Models\ProductInventory;
use App\Models\QualityControlInspection;
use App\Models\QualityControlTemplate;
use App\Models\SerializedUnit;
use App\Models\SerializedUnitConfigurationEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class QualityControlFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_is_additive_and_creates_no_business_rows(): void
    {
        $this->assertTrue(Schema::hasColumns('serialized_units', [
            'unit_code',
            'product_id',
            'product_inventory_id',
            'warehouse_id',
            'manufacturer_serial',
            'device_type',
            'status',
            'initial_configuration',
            'current_configuration',
        ]));

        $this->assertTrue(Schema::hasColumns('serialized_unit_configuration_events', [
            'serialized_unit_id',
            'event_type',
            'before_configuration',
            'after_configuration',
            'actor_user_id',
            'occurred_at',
        ]));

        $this->assertTrue(Schema::hasColumns('quality_control_templates', [
            'code',
            'device_type',
            'version',
            'active',
        ]));

        $this->assertTrue(Schema::hasColumns('quality_control_template_checks', [
            'quality_control_template_id',
            'section_key',
            'check_code',
            'input_type',
            'critical',
            'applicability_rules',
        ]));

        $this->assertTrue(Schema::hasColumns('quality_control_inspections', [
            'qc_number',
            'serialized_unit_id',
            'quality_control_template_id',
            'status',
            'cosmetic_grade',
            'configuration_snapshot',
            'order_requirement_snapshot',
            'verification_token',
        ]));

        $this->assertTrue(Schema::hasColumns('quality_control_inspection_results', [
            'quality_control_inspection_id',
            'check_code',
            'outcome',
            'numeric_value',
            'text_value',
            'evidence_required',
        ]));

        $this->assertDatabaseCount('serialized_units', 0);
        $this->assertDatabaseCount('quality_control_templates', 0);
        $this->assertDatabaseCount('quality_control_inspections', 0);
    }

    public function test_serialized_unit_keeps_original_and_current_configuration_separate(): void
    {
        $actor = User::factory()->create();
        $inventory = ProductInventory::factory()->create();

        $unit = SerializedUnit::query()->create([
            'unit_code' => 'TPZ-U-26-000001',
            'product_id' => $inventory->product_id,
            'product_inventory_id' => $inventory->id,
            'warehouse_id' => $inventory->warehouse_id,
            'manufacturer_serial' => '5CG1234567',
            'device_type' => QualityControlDeviceType::WindowsLaptop,
            'status' => SerializedUnitStatus::QcPending,
            'initial_configuration' => [
                'processor' => 'Intel Core i5-1135G7',
                'ram_gb' => 8,
                'storage_gb' => 256,
            ],
            'current_configuration' => [
                'processor' => 'Intel Core i5-1135G7',
                'ram_gb' => 16,
                'storage_gb' => 512,
            ],
            'received_by_user_id' => $actor->id,
            'received_at' => now(),
        ]);

        $this->assertSame(8, $unit->initial_configuration['ram_gb']);
        $this->assertSame(16, $unit->current_configuration['ram_gb']);
        $this->assertSame(256, $unit->initial_configuration['storage_gb']);
        $this->assertSame(512, $unit->current_configuration['storage_gb']);
        $this->assertSame(QualityControlDeviceType::WindowsLaptop, $unit->device_type);
        $this->assertSame(SerializedUnitStatus::QcPending, $unit->status);
    }

    public function test_configuration_history_is_immutable(): void
    {
        $actor = User::factory()->create();
        $inventory = ProductInventory::factory()->create();
        $unit = $this->unit($actor, $inventory);

        $event = SerializedUnitConfigurationEvent::query()->create([
            'serialized_unit_id' => $unit->id,
            'event_type' => 'upgrade',
            'before_configuration' => ['ram_gb' => 8, 'storage_gb' => 256],
            'after_configuration' => ['ram_gb' => 16, 'storage_gb' => 512],
            'notes' => 'Order configuration upgrade',
            'actor_user_id' => $actor->id,
            'occurred_at' => now(),
        ]);

        $this->expectException(LogicException::class);
        $event->update(['notes' => 'Changed later']);
    }

    public function test_template_and_inspection_preserve_device_specific_snapshot(): void
    {
        $actor = User::factory()->create();
        $inventory = ProductInventory::factory()->create();
        $unit = $this->unit($actor, $inventory);

        $template = QualityControlTemplate::query()->create([
            'code' => 'WINDOWS-LAPTOP',
            'name' => 'Windows Laptop QC',
            'device_type' => QualityControlDeviceType::WindowsLaptop,
            'version' => 1,
            'active' => true,
            'created_by_user_id' => $actor->id,
            'updated_by_user_id' => $actor->id,
        ]);

        $template->checks()->create([
            'section_key' => 'battery_power',
            'section_label' => 'Battery & Power',
            'check_code' => 'battery_health',
            'label' => 'Battery Health',
            'input_type' => QualityControlCheckInputType::Percentage,
            'required' => true,
            'critical' => true,
            'allow_na' => false,
            'evidence_on_fail' => true,
            'validation_rules' => ['min' => 80, 'max' => 100],
            'sequence' => 10,
        ]);

        $inspection = QualityControlInspection::query()->create([
            'qc_number' => 'QC-26-A7K29M',
            'serialized_unit_id' => $unit->id,
            'quality_control_template_id' => $template->id,
            'template_version' => 1,
            'status' => QualityControlInspectionStatus::Passed,
            'cosmetic_grade' => QualityControlCosmeticGrade::Excellent,
            'configuration_snapshot' => $unit->current_configuration,
            'order_requirement_snapshot' => [
                'ram_gb' => 16,
                'storage_gb' => 512,
            ],
            'verification_token' => str_repeat('a', 64),
            'report_revision' => 1,
            'started_by_user_id' => $actor->id,
            'completed_by_user_id' => $actor->id,
            'started_at' => now()->subMinutes(10),
            'completed_at' => now(),
        ]);

        $inspection->results()->create([
            'check_code' => 'battery_health',
            'section_key' => 'battery_power',
            'label_snapshot' => 'Battery Health',
            'input_type_snapshot' => QualityControlCheckInputType::Percentage,
            'required_snapshot' => true,
            'critical_snapshot' => true,
            'allow_na_snapshot' => false,
            'outcome' => QualityControlOutcome::Pass,
            'numeric_value' => 91,
            'evidence_required' => false,
            'sequence' => 10,
        ]);

        $inspection->load('results');

        $this->assertSame(16, $inspection->configuration_snapshot['ram_gb']);
        $this->assertSame(512, $inspection->order_requirement_snapshot['storage_gb']);
        $this->assertSame(QualityControlInspectionStatus::Passed, $inspection->status);
        $this->assertSame(QualityControlCosmeticGrade::Excellent, $inspection->cosmetic_grade);
        $this->assertSame(QualityControlOutcome::Pass, $inspection->results->first()->outcome);
        $this->assertSame('91.0000', $inspection->results->first()->numeric_value);
    }

    private function unit(User $actor, ProductInventory $inventory): SerializedUnit
    {
        return SerializedUnit::query()->create([
            'unit_code' => 'TPZ-U-26-'.str_pad((string) $inventory->id, 6, '0', STR_PAD_LEFT),
            'product_id' => $inventory->product_id,
            'product_inventory_id' => $inventory->id,
            'warehouse_id' => $inventory->warehouse_id,
            'manufacturer_serial' => 'SERIAL-'.$inventory->id,
            'device_type' => QualityControlDeviceType::WindowsLaptop,
            'status' => SerializedUnitStatus::QcPending,
            'initial_configuration' => ['ram_gb' => 8, 'storage_gb' => 256],
            'current_configuration' => ['ram_gb' => 16, 'storage_gb' => 512],
            'received_by_user_id' => $actor->id,
            'received_at' => now(),
        ]);
    }
}
