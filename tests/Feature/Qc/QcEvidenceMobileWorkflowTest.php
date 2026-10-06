<?php

namespace Tests\Feature\Qc;

use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\QcPermission;
use App\Filament\Resources\QcInspections\Pages\CreateQcInspection;
use App\Filament\Resources\QcInspections\Pages\EditQcInspection;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductInventory;
use App\Models\QcEvidence;
use App\Models\QcInspection;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\EmployeePermissionOverrideService;
use App\Services\Qc\QcEvidenceService;
use App\Services\Qc\QcInspectionService;
use App\Services\Qc\QcTemplateResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class QcEvidenceMobileWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $technician;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->owner = $this->actor(EmployeeRole::Owner);
        $this->technician = $this->actor(EmployeeRole::Staff);
        foreach ([QcPermission::View, QcPermission::Start, QcPermission::Update, QcPermission::Complete, QcPermission::ViewCustomerEvidence] as $permission) {
            app(EmployeePermissionOverrideService::class)->change($this->technician->employee, $permission->value, EmployeePermissionEffect::Allow, 'QC test', $this->owner);
        }
        $this->product = Product::factory()->create(['model' => 'EliteBook', 'processor' => 'Intel i5', 'ram' => '8GB', 'storage' => '256GB', 'touch_screen' => false]);
        $this->warehouse = Warehouse::factory()->create();
    }

    public function test_multiple_images_and_kinds_have_distinct_private_originals_and_watermarked_derivatives(): void
    {
        $inspection = $this->start();
        $files = [$this->photo(), $this->photo()];
        $proofs = app(QcEvidenceService::class)->uploadBatch($inspection, ['physical' => $files, 'display' => [$this->photo()]], false, $this->technician);
        $this->assertCount(3, $proofs);
        $this->assertCount(3, array_unique(array_column($proofs, 'public_id')));
        foreach ($proofs as $index => $proof) {
            $this->assertSame($inspection->id, $proof->inspection_id);
            $this->assertTrue($proof->customer_visible);
            $this->assertTrue(Storage::disk('local')->exists($proof->customer_path));
            $this->assertNotSame(Storage::disk('local')->get($proof->original_path), Storage::disk('local')->get($proof->customer_path));
            $this->assertSame(hash('sha256', Storage::disk('local')->get($proof->original_path)), $proof->checksum);
            $this->assertSame($this->technician->id, $proof->uploaded_by);
        }
        $this->assertSame(file_get_contents($files[0]->getRealPath()), Storage::disk('local')->get($proofs[0]->original_path));
        $this->assertSame(['physical', 'display'], app(QcEvidenceService::class)->progress($inspection)['complete']);
    }

    public function test_narrow_image_derivative_does_not_upscale_beyond_safe_dimension_budget(): void
    {
        $proof = app(QcEvidenceService::class)->upload($this->start(), UploadedFile::fake()->image('narrow.jpg', 1, 5000), 'serial', true, $this->technician);
        $dimensions = getimagesizefromstring(Storage::disk('local')->get($proof->customer_path));
        $this->assertSame(700, $dimensions[0]);
        $this->assertSame(5120, $dimensions[1]);
        $this->assertLessThan(16000000, $dimensions[0] * $dimensions[1]);
        $wide = app(QcEvidenceService::class)->upload($this->start(), UploadedFile::fake()->image('wide.jpg', 5000, 1), 'serial', true, $this->technician);
        $wideDimensions = getimagesizefromstring(Storage::disk('local')->get($wide->customer_path));
        $this->assertSame(1600, $wideDimensions[0]);
        $this->assertSame(121, $wideDimensions[1]);
    }

    public function test_mandatory_single_photo_ignores_false_visibility_but_additional_can_be_internal(): void
    {
        $inspection = $this->start();
        $service = app(QcEvidenceService::class);
        foreach (['serial', 'physical', 'display', 'system', 'upgrade'] as $kind) {
            $this->assertTrue($service->upload($inspection, $this->photo(), $kind, false, $this->technician)->customer_visible);
        }
        $this->assertFalse($service->uploadMany($inspection, [$this->photo(), $this->photo()], 'additional', false, $this->technician)[0]->customer_visible);
        $this->assertCount(4, $service->progress($inspection)['complete']);
    }

    public function test_more_photos_do_not_inflate_required_category_progress(): void
    {
        $inspection = $this->start();
        $service = app(QcEvidenceService::class);
        $service->uploadMany($inspection, [$this->photo(), $this->photo(), $this->photo()], 'serial', true, $this->technician);
        $progress = $service->progress($inspection);
        $this->assertCount(1, $progress['complete']);
        $this->assertSame(['physical', 'display', 'system'], $progress['missing']);
        Livewire::actingAs($this->technician)->test(EditQcInspection::class, ['record' => $inspection->id])->assertSee('Critical Evidence: 1 / 4 complete')->assertSee('3 photo(s)');
    }

    public function test_livewire_upload_auto_assigns_kind_and_preserves_inspection_and_form(): void
    {
        $inspection = $this->start();
        $other = $this->start();
        $component = Livewire::actingAs($this->technician)->test(EditQcInspection::class, ['record' => $inspection->id])
            ->set('data.public_remarks', 'Keep my unsaved entry')
            ->set('additionalCustomerVisible', false)->set('evidenceUploads.physical', [$this->photo(), $this->photo()])
            ->call('uploadEvidenceKind', 'physical')->assertHasNoErrors()->assertSee('2 photo(s)')->assertSee('Take Photo')->assertSee('Choose Photos');
        $this->assertSame('Keep my unsaved entry', $component->instance()->data['public_remarks']);
        $this->assertSame(2, $inspection->evidence()->where('customer_visible', true)->count());
        $this->assertSame(0, $other->evidence()->count());
    }

    public function test_native_batch_action_uploads_multiple_categories_without_kind_selection(): void
    {
        $inspection = $this->start();
        Livewire::actingAs($this->technician)->test(EditQcInspection::class, ['record' => $inspection->id])
            ->callAction('uploadEvidence', data: ['physical' => [$this->photo(), $this->photo()], 'display' => [$this->photo()]])->assertHasNoErrors();
        $this->assertSame(2, $inspection->evidence()->where('kind', 'physical')->count());
        $this->assertSame(1, $inspection->evidence()->where('kind', 'display')->count());
        $this->assertSame(3, $inspection->evidence()->where('customer_visible', true)->count());
    }

    public function test_missing_categories_are_reported_together_and_form_is_not_partially_saved(): void
    {
        $inspection = $this->start();
        $this->ready($inspection);
        app(QcEvidenceService::class)->upload($inspection, $this->photo(), 'serial', false, $this->technician);
        $component = Livewire::actingAs($this->technician)->test(EditQcInspection::class, ['record' => $inspection->id])
            ->set('data.public_remarks', 'Unsaved value')->callAction('completeQc')
            ->assertHasErrors(['data.evidence.physical', 'data.evidence.display', 'data.evidence.system'])->assertDispatched('qc-evidence-missing');
        $this->assertSame('Unsaved value', $component->instance()->data['public_remarks']);
        $this->assertNull($inspection->fresh()->public_remarks);
        $this->assertDatabaseCount('qc_certificates', 0);
    }

    public function test_foreign_inspection_and_completed_inspection_reject_uploads(): void
    {
        $foreign = $this->start(actor: $this->owner);
        try {
            app(QcEvidenceService::class)->uploadMany($foreign, [$this->photo()], 'serial', true, $this->technician);
            $this->fail('Foreign inspection upload must fail.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('qc_evidence', 0);
        }
        $inspection = $this->start();
        $this->ready($inspection);
        $this->proofs($inspection);
        app(QcInspectionService::class)->complete($inspection, $this->technician);
        $before = Storage::disk('local')->allFiles();
        $this->assertValidation(fn () => app(QcEvidenceService::class)->uploadMany($inspection, [$this->photo()], 'serial', true, $this->technician));
        $this->assertSame($before, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('qc_evidence', 4);
    }

    public function test_invalid_batch_and_over_limit_have_no_partial_evidence(): void
    {
        $inspection = $this->start();
        $service = app(QcEvidenceService::class);
        $this->assertValidation(fn () => $service->uploadBatch($inspection, ['physical' => [$this->photo()], 'system' => [UploadedFile::fake()->create('bad.txt', 1)]], true, $this->technician));
        $this->assertValidation(fn () => $service->uploadMany($inspection, array_fill(0, 11, $this->photo()), 'physical', true, $this->technician));
        $this->assertDatabaseCount('qc_evidence', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_late_batch_failure_rolls_back_records_audits_and_generated_files(): void
    {
        $inspection = $this->start();
        $service = new class extends QcEvidenceService
        {
            private int $calls = 0;

            public function upload(QcInspection $inspection, UploadedFile $file, string $kind, bool $customerVisible, User $actor): QcEvidence
            {
                if (++$this->calls === 2) {
                    throw new \RuntimeException('Synthetic storage failure');
                }

                return parent::upload($inspection, $file, $kind, $customerVisible, $actor);
            }
        };
        try {
            $service->uploadMany($inspection, [$this->photo(), $this->photo()], 'physical', true, $this->technician);
            $this->fail('Batch must fail.');
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('qc_evidence', 0);
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseMissing('activity_logs', ['event' => 'qc.evidence_uploaded']);
    }

    public function test_complete_and_public_view_do_not_expose_internal_originals_or_mutate_stock(): void
    {
        ProductInventory::factory()->create(['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'available_quantity' => 12]);
        $inspection = $this->start();
        $tables = ['product_inventories', 'inventory_allocation_balances', 'inventory_allocation_events', 'inventory_reservations', 'stock_movements', 'orders', 'order_items', 'products'];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
        $before = $snapshot();
        $this->ready($inspection);
        $this->proofs($inspection);
        $internal = app(QcEvidenceService::class)->upload($inspection, $this->photo(), 'additional', false, $this->technician);
        $certificate = app(QcInspectionService::class)->complete($inspection, $this->technician);
        $this->get(route('qc.verify', $certificate->public_token))->assertOk()->assertDontSee($internal->public_id)->assertDontSee($internal->original_path);
        $this->get(route('qc.evidence.public', ['token' => $certificate->public_token, 'id' => $internal->public_id]))->assertNotFound();
        $proof = $inspection->evidence()->where('kind', 'serial')->sole();
        $this->assertSame(Storage::disk('local')->get($proof->customer_path), $this->get(route('qc.evidence.public', ['token' => $certificate->public_token, 'id' => $proof->public_id]).'?original=1')->getContent());
        $this->assertSame($before, $snapshot());
    }

    public function test_camera_controls_multiple_inputs_and_manual_serial_scanner_render(): void
    {
        $inspection = $this->start();
        $component = Livewire::actingAs($this->technician)->test(EditQcInspection::class, ['record' => $inspection->id]);
        foreach (['serial', 'physical', 'display', 'system', 'additional'] as $kind) {
            $component->assertSeeHtml('data-testid="qc-evidence-'.$kind.'"');
        }
        $component->assertSeeHtml('capture="environment"')->assertSeeHtml('multiple')->assertSee('Choose Photos')->assertDontSee('Add Critical Evidence');
        $create = Livewire::actingAs($this->technician)->test(CreateQcInspection::class)->assertSee('Serial / IMEI');
        $create->set('data.serial', 'MANUAL-123')->assertSet('data.serial', 'MANUAL-123');
        $this->assertStringContainsString('Scan Serial / IMEI', $create->html());
        $this->assertStringContainsString('Manual', file_get_contents(public_path('js/qc-device-workflow.js')));
        $create->assertSee('Scan Serial / IMEI')->assertSee('does not replace the required');
    }

    public function test_device_templates_are_resolved_snapshotted_and_only_applicable_checks_appear(): void
    {
        $windows = $this->start();
        $this->assertSame('windows_laptop', $windows->device->device_type);
        $this->assertSame(72, $windows->checks()->count());
        $mac = $this->start($this->deviceProduct('Laptop', 'MacBook Pro'));
        $this->assertSame('macbook', $mac->device->device_type);
        $this->assertSame('macOS installed', $mac->checks()->where('check_key', 'os')->sole()->definition['label']);
        $this->assertFalse($mac->checks()->where('check_key', 'touch_id')->sole()->applicable);
        $tabletProduct = $this->deviceProduct('Tablet', 'iPad Air');
        $tablet = $this->start($tabletProduct);
        $this->assertSame('tablet', $tablet->device->device_type);
        foreach (['keys', 'trackpad', 'bios', 'drivers', 'ram', 'fan'] as $key) {
            $this->assertFalse($tablet->checks()->where('check_key', $key)->exists());
        }
        foreach (['imei', 'touch_id', 'face_id', 'battery_cycles'] as $key) {
            $this->assertFalse($tablet->checks()->where('check_key', $key)->sole()->applicable);
        }
        $this->assertTrue($tablet->checks()->where('check_key', 'touchscreen')->sole()->applicable);
        $snapshot = $tablet->product_snapshot['qc_template'];
        $tabletProduct->update(['category_id' => $this->product->category_id, 'category' => 'Laptop', 'model' => 'Changed model']);
        $this->assertSame($snapshot, app(QcTemplateResolver::class)->forInspection($tablet->fresh()));
        Livewire::actingAs($this->technician)->test(EditQcInspection::class, ['record' => $tablet->id])->assertSee('Tablet / iPad')->assertDontSee('Detected RAM (MB)')->assertDontSee('Keyboard / Input');
    }

    public function test_tablet_completion_has_no_mandatory_laptop_fields_and_re_qc_keeps_template(): void
    {
        $tablet = $this->start($this->deviceProduct('Tablet', 'iPad Air'), features: ['cellular', 'face_id', 'battery_health']);
        $this->assertTrue($tablet->checks()->where('check_key', 'imei')->sole()->applicable);
        $this->assertTrue($tablet->checks()->where('check_key', 'face_id')->sole()->applicable);
        $this->ready($tablet);
        $this->proofs($tablet);
        $certificate = app(QcInspectionService::class)->complete($tablet, $this->technician);
        $this->assertCount(4, $certificate->snapshot['evidence']);
        $this->assertNull($certificate->snapshot['final']['ram_mb']);
        $this->get(route('qc.verify', $certificate->public_token))->assertOk()->assertDontSee('0 GB RAM')->assertDontSee('Windows')->assertSee('iPadOS');
        $new = app(QcInspectionService::class)->reopen($tablet, 'Retest tablet', $this->owner);
        $this->assertSame($tablet->product_snapshot['qc_template'], $new->product_snapshot['qc_template']);
        $this->assertFalse($new->checks()->where('check_key', 'bios')->exists());
    }

    public function test_macbook_completes_with_apple_checks_and_selected_optional_hardware(): void
    {
        $mac = $this->start($this->deviceProduct('Laptop', 'MacBook Pro'), features: ['touch_id', 'magsafe', 'battery_cycles']);
        $this->assertTrue($mac->checks()->where('check_key', 'touch_id')->sole()->applicable);
        $this->assertTrue($mac->checks()->where('check_key', 'magsafe')->sole()->applicable);
        $this->ready($mac);
        $this->proofs($mac);
        $certificate = app(QcInspectionService::class)->complete($mac, $this->technician);
        $this->assertSame('macbook', $certificate->snapshot['product']['device_type']);
        $this->get(route('qc.verify', $certificate->public_token))->assertOk()->assertSee('macOS installed')->assertSee('Apple Diagnostics')->assertSee('Activation Lock cleared')->assertDontSee('Windows')->assertDontSee('BIOS')->assertDontSee('Drivers');
    }

    public function test_tablet_changed_storage_still_requires_upgrade_checks_and_fifth_proof(): void
    {
        $tablet = $this->start($this->deviceProduct('Tablet', 'iPad Air'));
        $service = app(QcInspectionService::class);
        $final = ['cpu' => null, 'ram_mb' => null, 'storage_gb' => 512, 'os' => 'iPadOS 18'];
        $service->update($tablet, ['final_configuration' => $final], $this->technician);
        $checks = $tablet->checks()->where('applicable', true)->get()->mapWithKeys(fn ($check) => [$check->check_key => ['result' => 'pass'] + ($check->definition['measurement'] ? ['measurement' => 70] : [])])->all();
        $service->update($tablet, ['checks' => $checks, 'final_configuration' => $final, 'grade' => 'A'], $this->technician);
        foreach (['serial', 'physical', 'display', 'system'] as $kind) {
            app(QcEvidenceService::class)->upload($tablet, $this->photo(), $kind, false, $this->technician);
        }
        try {
            $service->complete($tablet, $this->technician);
            $this->fail('Upgrade proof must be required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('evidence.upgrade', $exception->errors());
        }
        app(QcEvidenceService::class)->upload($tablet, $this->photo(), 'upgrade', false, $this->technician);
        $certificate = $service->complete($tablet, $this->technician);
        $this->assertCount(5, $certificate->snapshot['evidence']);
        $this->assertSame(256, $certificate->snapshot['original']['storage_gb']);
        $this->assertSame(512, $certificate->snapshot['final']['storage_gb']);
        $this->assertStringContainsString('512GB', $certificate->snapshot['product']['title']);
        $this->assertSame('Tablet', $tablet->device->product->fresh()->displayCategoryName());
    }

    public function test_unsupported_devices_and_wrong_template_features_fail_closed(): void
    {
        $monitor = $this->deviceProduct('Monitor', 'MacBook external monitor');
        $this->assertFalse(app(QcTemplateResolver::class)->supports($monitor));
        $this->assertValidation(fn () => $this->start($monitor));
        $this->assertValidation(fn () => $this->start(features: ['face_id']));
        $this->assertDatabaseCount('qc_devices', 0);
    }

    private function actor(EmployeeRole $role): User
    {
        $employee = Employee::factory()->role($role)->create();
        $email = 'qc-mobile-'.str()->random(10).'@techpointzone.com';
        $employee->update(['email' => $email]);
        $employee->user->update(['email' => $email]);

        return $employee->user;
    }

    private function deviceProduct(string $category, string $model): Product
    {
        $record = ProductCategory::query()->firstOrCreate(['normalized_name' => strtolower($category)], ['name' => $category, 'status' => true]);

        return Product::factory()->create(['category' => $category, 'category_id' => $record->id, 'model' => $model, 'name' => $model, 'ram' => '8GB', 'storage' => '256GB', 'touch_screen' => false]);
    }

    private function start(?Product $product = null, ?User $actor = null, array $features = []): QcInspection
    {
        return app(QcInspectionService::class)->start(['product_id' => ($product ?? $this->product)->id, 'warehouse_id' => $this->warehouse->id, 'serial' => 'QC-MOBILE-'.str()->random(10), 'features' => $features, 'idempotency_key' => (string) str()->uuid()], $actor ?? $this->technician);
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->image('photo-'.str()->random(8).'.jpg', 800, 600);
    }

    private function ready(QcInspection $inspection): void
    {
        $tablet = $inspection->device->device_type === 'tablet';
        $checks = $inspection->checks()->where('applicable', true)->get()->mapWithKeys(fn ($check) => [$check->check_key => ['result' => 'pass'] + ($check->definition['measurement'] ? ['measurement' => 70] : [])])->all();
        app(QcInspectionService::class)->update($inspection, ['checks' => $checks, 'grade' => 'A', 'final_configuration' => ['cpu' => $tablet ? null : 'Intel i5', 'ram_mb' => $tablet ? null : 8192, 'storage_gb' => 256, 'os' => $tablet ? 'iPadOS 18' : ($inspection->device->device_type === 'macbook' ? 'macOS' : 'Windows 11')]], $this->technician);
    }

    private function proofs(QcInspection $inspection): void
    {
        foreach (app(QcEvidenceService::class)->requiredKinds($inspection) as $kind) {
            app(QcEvidenceService::class)->uploadMany($inspection, [$this->photo()], $kind, false, $this->technician);
        }
    }

    private function assertValidation(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected validation.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }
}
