<?php

namespace Tests\Feature\Mobile;

use App\Enums\ComponentType;
use App\Enums\EmployeeRole;
use App\Enums\HardwareSubsystem;
use App\Enums\QcInspectionStatus;
use App\Models\Component;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\QcCertificate;
use App\Models\QcDevice;
use App\Models\QcEvidence;
use App\Models\QcInspection;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileBatch7ManagementOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_management_reuses_authorized_audited_actions(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $manager = $this->user(EmployeeRole::Manager);

        $brandId = $this->as($owner)->postJson('/api/mobile/v1/workspace/catalog/brands', ['name' => 'Mobile Batch Seven'])
            ->assertCreated()->assertJsonPath('data.active', true)->json('data.id');
        $this->as($owner)->putJson('/api/mobile/v1/workspace/catalog/brands/'.$brandId, ['name' => 'Mobile Batch 7'])
            ->assertOk()->assertJsonPath('data.name', 'Mobile Batch 7');
        $this->as($owner)->postJson('/api/mobile/v1/workspace/catalog/brands/'.$brandId.'/status', [
            'active' => false, 'reason' => 'Retired after Mobile catalog verification',
        ])->assertOk()->assertJsonPath('data.active', false);
        $this->assertDatabaseHas('activity_logs', ['event' => 'catalog.brand.created']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'catalog.brand.renamed']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'catalog.brand.status_changed']);

        $this->as($manager)->postJson('/api/mobile/v1/workspace/catalog/categories', ['name' => 'Unauthorized category'])
            ->assertForbidden();
    }

    public function test_recipe_and_hardware_relationship_endpoints_use_existing_services(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $brand = ProductBrand::factory()->create(['status' => true]);
        $category = ProductCategory::factory()->create(['status' => true]);
        $product = Product::factory()->create(['brand_id' => $brand->id, 'category_id' => $category->id]);
        $component = Component::factory()->create([
            'component_type' => ComponentType::Ram,
            'capacity_value' => 8,
            'capacity_unit' => 'gb',
            'interface_type' => 'DDR4',
        ]);

        $this->as($owner)->putJson('/api/mobile/v1/workspace/products/'.$product->id.'/intelligence/profile', [
            'ram_upgradeable' => true, 'max_supported_ram_mb' => 32768, 'storage_upgradeable' => true,
            'notes' => 'Batch 7 hardware relationship',
            'slots' => [[
                'subsystem' => HardwareSubsystem::Ram->value, 'slot_key' => 'RAM-1', 'interface_type' => 'DDR4',
                'is_soldered' => false, 'is_occupied' => true, 'base_component_id' => $component->id,
                'base_capacity_value' => 8, 'base_capacity_unit' => 'gb', 'position' => 0,
            ]],
        ])->assertOk()->assertJsonPath('data.hardware_profile.slots.0.base_component_id', $component->id);

        $configuration = $this->as($owner)->postJson('/api/mobile/v1/workspace/products/'.$product->id.'/intelligence/configurations', [
            'display_name' => 'Keep 8GB', 'target_ram_mb' => 8192, 'target_storage_total_gb' => null,
            'target_storage_layout' => [], 'suggested_selling_addon' => 0, 'default_selling_price' => null, 'active' => true,
        ])->assertOk();
        $configurationId = $configuration->json('data.configurations.0.id');
        $payload = [
            'name' => 'Keep base RAM', 'preferred' => true, 'priority' => 10, 'labour_unit_cost' => 5, 'active' => true,
            'lines' => [[
                'sequence' => 1, 'operation' => 'keep', 'source_slot_key' => 'RAM-1', 'target_slot_key' => null,
                'install_component_id' => null, 'recovered_component_id' => null, 'quantity_per_laptop' => 1,
                'recovery_valuation_method' => 'not_applicable',
            ]],
        ];
        $created = $this->as($owner)->postJson('/api/mobile/v1/workspace/products/'.$product->id.'/intelligence/configurations/'.$configurationId.'/recipes', $payload)
            ->assertOk()->assertJsonPath('data.configurations.0.recipes.0.name', 'Keep base RAM');
        $recipeId = $created->json('data.configurations.0.recipes.0.id');
        $this->as($owner)->putJson('/api/mobile/v1/workspace/products/'.$product->id.'/intelligence/recipes/'.$recipeId, [
            ...$payload, 'name' => 'Keep verified base RAM',
        ])->assertOk()->assertJsonPath('data.configurations.0.recipes.0.name', 'Keep verified base RAM');
        $this->assertDatabaseHas('activity_logs', ['event' => 'upgrade_recipe.created']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'upgrade_recipe.updated']);
    }

    public function test_qc_passport_evidence_and_report_remain_permission_scoped(): void
    {
        Storage::fake('local');
        $owner = $this->user(EmployeeRole::Owner);
        $outsider = $this->user(EmployeeRole::Staff);
        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $device = QcDevice::query()->create([
            'reference' => 'TPZ-QC-2026-700001', 'serial' => 'BATCH7-SERIAL',
            'serial_key' => hash('sha256', 'BATCH7-SERIAL'), 'device_type' => 'laptop', 'product_id' => $product->id,
        ]);
        $inspection = QcInspection::query()->create([
            'device_id' => $device->id, 'active_device_id' => null, 'version' => 1,
            'technician_user_id' => $owner->id, 'warehouse_id' => $warehouse->id,
            'status' => QcInspectionStatus::InProgress, 'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'batch-7-qc'),
            'product_snapshot' => ['title' => $product->name, 'sku' => $product->sku],
            'original_configuration' => [], 'final_configuration' => [], 'features' => [],
            'grade' => 'A', 'completed_at' => null,
        ]);
        Storage::disk('local')->put('qc/originals/batch7.bin', 'original');
        Storage::disk('local')->put('qc/customer/batch7.jpg', 'customer');
        $evidence = QcEvidence::query()->create([
            'public_id' => (string) Str::uuid(), 'inspection_id' => $inspection->id, 'uploaded_by' => $owner->id,
            'kind' => 'serial', 'customer_visible' => true, 'original_path' => 'qc/originals/batch7.bin',
            'customer_path' => 'qc/customer/batch7.jpg', 'checksum' => hash('sha256', 'original'), 'uploaded_at' => now(),
        ]);
        $inspection->forceFill(['status' => QcInspectionStatus::Completed, 'completed_at' => now()])->save();
        $certificate = QcCertificate::query()->create([
            'inspection_id' => $inspection->id, 'device_id' => $device->id, 'version' => 1,
            'public_token' => hash('sha256', 'batch-7-public-token'),
            'snapshot' => ['reference' => $device->reference, 'serial' => $device->serial, 'grade' => 'A', 'checks' => [], 'evidence' => [$evidence->public_id]],
            'certified_at' => now(),
        ]);

        $this->as($owner)->getJson('/api/mobile/v1/workspace/qc/inspections/'.$inspection->id)
            ->assertOk()->assertJsonPath('inspection.certificate.id', $certificate->id)
            ->assertJsonPath('inspection.evidence.0.id', $evidence->id)
            ->assertJsonMissingPath('inspection.certificate.public_token');
        $this->as($owner)->getJson('/api/mobile/v1/workspace/qc/devices/'.$device->id)
            ->assertOk()->assertJsonPath('data.inspections.0.target.module', 'qc/inspections');
        $this->as($owner)->get('/api/mobile/v1/workspace/qc/inspections/'.$inspection->id.'/evidence/'.$evidence->id)
            ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->as($owner)->getJson('/api/mobile/v1/workspace/reports/qc_operations?from='.now()->subDay()->toDateString().'&to='.now()->toDateString())
            ->assertOk()->assertJsonPath('data.rows.0._target_id', $inspection->id)
            ->assertJsonPath('data.rows.0._target_module', 'qc/inspections');
        $this->as($outsider)->getJson('/api/mobile/v1/workspace/qc/devices/'.$device->id)->assertForbidden();
        $this->as($outsider)->getJson('/api/mobile/v1/workspace/reports/qc_operations')->assertForbidden();
    }

    private function user(EmployeeRole $role): User
    {
        $email = 'mobile-batch7-'.Str::lower(Str::random(12)).'@techpointzone.com';
        $user = User::factory()->create(['email' => $email]);
        Employee::factory()->for($user)->role($role)->create(['email' => $email, 'status' => true]);

        return $user->refresh();
    }

    private function as(User $user): static
    {
        app('auth')->forgetGuards();

        return $this->withToken($user->createToken('Mobile Batch 7 test')->plainTextToken);
    }
}
