<?php

namespace Tests\Feature\Qc;

use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\InventoryPermission;
use App\Enums\QcPermission;
use App\Filament\Resources\QcInspections\Pages\CreateQcInspection;
use App\Filament\Resources\QcInspections\Pages\EditQcInspection;
use App\Filament\Resources\QcInspections\Pages\ListQcInspections;
use App\Filament\Resources\QcInspections\Pages\ViewQcInspection;
use App\Filament\Resources\QcInspections\QcInspectionResource;
use App\Models\Employee;
use App\Models\OrderItemUpgradeSelection;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\QcInspection;
use App\Models\SalesConfiguration;
use App\Models\UpgradeRecipe;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\AccessControlModuleRegistry;
use App\Services\Authorization\EmployeePermissionCatalog;
use App\Services\Authorization\EmployeePermissionOverrideService;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\QcAuthorization;
use App\Services\BusinessTimezone;
use App\Services\Orders\OrderService;
use App\Services\Qc\LaptopQcTemplate;
use App\Services\Qc\QcDocumentService;
use App\Services\Qc\QcEvidenceService;
use App\Services\Qc\QcInspectionService;
use chillerlan\QRCode\QRCode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class QcDevicePassportTest extends TestCase
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
        $this->product = Product::factory()->create(['model' => 'EliteBook 640 G4', 'processor' => 'Intel Core i5 8th Gen', 'ram' => '8GB', 'storage' => '256GB', 'touch_screen' => false]);
        $this->warehouse = Warehouse::factory()->create();
        foreach ([QcPermission::View, QcPermission::Start, QcPermission::Update, QcPermission::Complete, QcPermission::PrintCertificate, QcPermission::PrintLabel, QcPermission::ViewCustomerEvidence] as $permission) {
            $this->grant($permission);
        }
    }

    public function test_catalog_registry_and_role_defaults_are_reconciled_without_financial_access(): void
    {
        $this->assertSame(['missing' => [], 'unknown' => [], 'duplicates' => []], app(AccessControlModuleRegistry::class)->reconcile());
        $this->assertSame('Quality Control', app(AccessControlModuleRegistry::class)->keyed()['quality_control']['label']);
        foreach (QcPermission::cases() as $permission) {
            $this->assertNotNull(app(EmployeePermissionCatalog::class)->find($permission->value));
            $this->assertSame($permission !== QcPermission::FocusedWorkspace, app(QcAuthorization::class)->allows($this->owner, $permission));
        }
        $this->assertFalse(app(QcAuthorization::class)->allows($this->technician, QcPermission::ViewAll));
        $this->assertFalse(app(QcAuthorization::class)->allows($this->technician, QcPermission::Reopen));
        $this->assertFalse(app(InventoryAuthorization::class)->allows($this->technician, InventoryPermission::ViewFinancials));
    }

    public function test_authorized_technician_and_owner_render_workspace_but_ungranted_employee_is_denied(): void
    {
        foreach ([$this->owner, $this->technician] as $actor) {
            $this->actingAs($actor)->get(QcInspectionResource::getUrl())->assertOk()->assertSee('Quality Control');
            Livewire::actingAs($actor)->test(ListQcInspections::class)->assertOk();
            Livewire::actingAs($actor)->test(CreateQcInspection::class)->assertOk();
        }
        $other = $this->actor(EmployeeRole::Staff);
        $this->actingAs($other)->get(QcInspectionResource::getUrl())->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(QcInspectionService::class)->start($this->startData(), $other);
    }

    public function test_start_records_identity_configuration_72_stable_checks_and_retry_without_stock_mutations(): void
    {
        $inventory = ProductInventory::factory()->create(['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'available_quantity' => 5, 'average_cost' => '100.0000']);
        $before = $this->stockSnapshot();
        $data = $this->startData();
        $inspection = app(QcInspectionService::class)->start($data, $this->technician);
        $this->assertSame($this->product->id, $inspection->device->product_id);
        $this->assertSame('5CG9117JYW', $inspection->device->serial);
        $this->assertMatchesRegularExpression('/^TPZ-QC-\d{4}-\d{6}$/', $inspection->device->reference);
        $this->assertSame(72, $inspection->checks()->count());
        $this->assertSame(12, count(collect(app(LaptopQcTemplate::class)->checks())->groupBy('group')));
        $this->assertSame(56, $inspection->checks()->where('applicable', true)->count());
        $this->assertSame(8192, $inspection->original_configuration['ram_mb']);
        $this->assertSame(256, $inspection->original_configuration['storage_gb']);
        $this->assertSame($inspection->id, app(QcInspectionService::class)->start($data, $this->technician)->id);
        $this->assertDatabaseCount('qc_devices', 1);
        $this->assertDatabaseCount('qc_inspections', 1);
        $this->assertSame($before, $this->stockSnapshot());
        $this->assertDatabaseHas('activity_logs', ['event' => 'qc.started']);
    }

    public function test_start_livewire_and_edit_form_render_all_sections_and_keep_data_on_validation_error(): void
    {
        $create = Livewire::actingAs($this->technician)->test(CreateQcInspection::class)->fillForm(['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'serial' => 'UI-SERIAL-001', 'features' => []])->call('create')->assertHasNoFormErrors();
        $inspection = QcInspection::query()->sole();
        $edit = Livewire::actingAs($this->technician)->test(EditQcInspection::class, ['record' => $inspection->id])->assertOk()->assertSee('Final Tested Configuration')->assertSee('Complete QC')->assertSee('Upload Multiple Evidence')->assertSee('Take Photo')->assertSee('Choose Photos');
        $edit->set('data.checks.wifi.result', 'fail')->set('data.checks.wifi.notes', '')->call('save')->assertHasErrors(['data.checks.wifi.notes']);
        $this->assertSame('fail', $edit->instance()->data['checks']['wifi']['result']);
        Livewire::actingAs($this->technician)->test(ViewQcInspection::class, ['record' => $inspection->id])->assertOk()->assertSee('Critical Evidence');
    }

    public function test_pass_all_excludes_measured_fields_and_na_is_only_allowed_by_definition(): void
    {
        $inspection = $this->start();
        app(QcInspectionService::class)->passGroup($inspection, 'CPU / RAM / Storage', $this->technician);
        $this->assertNull($inspection->checks()->where('check_key', 'ssd_health')->sole()->result);
        $this->assertSame('pass', $inspection->checks()->where('check_key', 'smart')->sole()->result);
        $this->assertNotEmpty($inspection->checks()->where('check_key', 'smart')->sole()->detail);
        $this->assertValidation(fn () => app(QcInspectionService::class)->update($inspection, ['checks' => ['wifi' => ['result' => 'na']]], $this->technician), 'checks.wifi.result');
        $this->assertValidation(fn () => app(QcInspectionService::class)->update($inspection, ['checks' => ['ssd_health' => ['result' => 'pass']]], $this->technician), 'checks.ssd_health.measurement');
        $optional = $this->start(['serial' => 'SECOND-DEVICE', 'features' => ['ethernet']]);
        app(QcInspectionService::class)->update($optional, ['checks' => ['ethernet' => ['result' => 'na']]], $this->technician);
        $this->assertSame('na', $optional->checks()->where('check_key', 'ethernet')->sole()->result);
    }

    public function test_missing_answers_configuration_grade_and_evidence_block_completion(): void
    {
        $inspection = $this->start();
        $this->assertValidation(fn () => app(QcInspectionService::class)->complete($inspection, $this->technician), 'grade');
        $this->ready($inspection);
        $this->assertValidation(fn () => app(QcInspectionService::class)->complete($inspection, $this->technician), 'evidence.serial');
        $this->proofs($inspection);
        app(QcInspectionService::class)->update($inspection, ['checks' => ['wifi' => ['result' => 'fail', 'notes' => 'Connection fails']]], $this->technician);
        $this->assertSame('rework_required', $inspection->fresh()->status->value);
        $this->assertValidation(fn () => app(QcInspectionService::class)->complete($inspection, $this->technician), 'checks.wifi.result');
        $this->assertDatabaseCount('qc_certificates', 0);
        $this->assertDatabaseHas('activity_logs', ['event' => 'qc.failed']);
    }

    public function test_original_upload_is_untouched_and_watermarked_derivative_has_server_timestamp(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 6)->setTime(14, 42, 18));
        $inspection = $this->start();
        $file = UploadedFile::fake()->image('serial.jpg', 800, 600);
        $original = file_get_contents($file->getRealPath());
        $proof = app(QcEvidenceService::class)->upload($inspection, $file, 'serial', true, $this->technician);
        $this->assertSame($original, Storage::disk('local')->get($proof->original_path));
        $this->assertSame(hash('sha256', $original), $proof->checksum);
        $this->assertNotSame($original, Storage::disk('local')->get($proof->customer_path));
        $this->assertSame(720, getimagesizefromstring(Storage::disk('local')->get($proof->customer_path))[1]);
        $this->assertSame(now()->toIso8601String(), $proof->uploaded_at->toIso8601String());
        $this->assertArrayNotHasKey('original_path', $proof->toArray());
        $this->assertDatabaseHas('activity_logs', ['event' => 'qc.evidence_uploaded']);
    }

    public function test_four_proofs_complete_immediately_without_manager_and_freeze_snapshots(): void
    {
        $inspection = $this->start();
        $before = $this->stockSnapshot();
        $this->ready($inspection);
        $this->proofs($inspection);
        $certificate = app(QcInspectionService::class)->complete($inspection, $this->technician);
        $this->assertSame('completed', $inspection->fresh()->status->value);
        $this->assertSame(56, count($certificate->snapshot['checks']));
        $this->assertSame(4, count($certificate->snapshot['evidence']));
        $this->assertSame($this->technician->employee->name, $certificate->snapshot['technician']);
        $this->assertSame(8192, $certificate->snapshot['original']['ram_mb']);
        $this->assertSame(8192, $certificate->snapshot['final']['ram_mb']);
        $this->assertSame('8GB', $this->product->fresh()->ram);
        $this->assertSame($before, $this->stockSnapshot());
        $this->assertSame($certificate->id, app(QcInspectionService::class)->complete($inspection, $this->technician)->id);
        $this->assertDatabaseCount('qc_certificates', 1);
        $this->assertDatabaseHas('activity_logs', ['event' => 'qc.completed']);
    }

    public function test_upgrade_requires_fifth_proof_and_final_requested_specs(): void
    {
        $order = app(OrderService::class)->saveDraft(new SaveAndReserveOrderData($this->warehouse->id, null, null, now()->toDateString(), $this->owner->employee->id, null, [new OrderItemData($this->product->id, 1, '250.00')], (string) str()->uuid()), $this->owner);
        $configuration = SalesConfiguration::query()->create(['product_id' => $this->product->id, 'hardware_profile_version' => 1, 'display_name' => '16GB / 512GB', 'target_ram_mb' => 16384, 'target_storage_total_gb' => 512, 'target_storage_layout' => [], 'created_by_user_id' => $this->owner->id, 'updated_by_user_id' => $this->owner->id]);
        $recipe = UpgradeRecipe::query()->create(['sales_configuration_id' => $configuration->id, 'hardware_profile_version' => 1, 'name' => 'QC fixture', 'created_by_user_id' => $this->owner->id, 'updated_by_user_id' => $this->owner->id]);
        OrderItemUpgradeSelection::query()->create(['order_item_id' => $order->items->sole()->id, 'sales_configuration_id' => $configuration->id, 'upgrade_recipe_id' => $recipe->id, 'hardware_profile_version' => 1, 'configuration_snapshot' => ['display_name' => '16GB / 512GB', 'target_ram_mb' => 16384, 'target_storage_total_gb' => 512, 'private_cost' => 'SECRET_COST'], 'recipe_snapshot' => [], 'recovery_snapshot' => [], 'selected_by_user_id' => $this->owner->id]);
        $inspection = $this->start(['order_item_id' => $order->items->sole()->id]);
        $this->assertSame($order->id, $inspection->order_id);
        $this->assertSame(16384, $inspection->requested_configuration['target_ram_mb']);
        $this->assertArrayNotHasKey('private_cost', $inspection->requested_configuration);
        $this->ready($inspection);
        $this->proofs($inspection);
        $this->assertValidation(fn () => app(QcInspectionService::class)->complete($inspection, $this->technician), 'evidence.upgrade');
        $this->proofs($inspection, ['upgrade']);
        app(QcInspectionService::class)->update($inspection, ['final_configuration' => ['cpu' => 'i5', 'ram_mb' => 8192, 'storage_gb' => 512, 'os' => 'Windows 11']], $this->technician);
        $this->assertValidation(fn () => app(QcInspectionService::class)->complete($inspection, $this->technician), 'final_configuration.ram_mb');
        $this->ready($inspection);
        $certificate = app(QcInspectionService::class)->complete($inspection, $this->technician);
        $this->assertCount(5, $certificate->snapshot['evidence']);
        $this->assertCount(62, $certificate->snapshot['checks']);
        $this->assertSame('8GB', $this->product->fresh()->ram);
        Livewire::actingAs($this->technician)->test(ViewQcInspection::class, ['record' => $inspection->id])->assertSee('CUSTOMER REQUIRED CONFIGURATION')->assertDontSee('SECRET_COST');
    }

    public function test_completed_records_cannot_be_mutated_by_service_livewire_or_certificate_sql(): void
    {
        [$inspection, $certificate] = $this->certified();
        $before = $certificate->snapshot;
        $this->assertValidation(fn () => app(QcInspectionService::class)->update($inspection, ['grade' => 'C'], $this->technician), 'checks');
        Livewire::actingAs($this->technician)->test(EditQcInspection::class, ['record' => $inspection->id])->assertForbidden();
        try {
            $certificate->update(['snapshot' => ['forged' => true]]);
            $this->fail('Certificate update must be denied.');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
        try {
            DB::table('qc_certificates')->where('id', $certificate->id)->update(['snapshot' => '{}']);
            $this->fail('SQL update must be denied.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }
        try {
            $inspection->checks()->first()->update(['result' => 'fail']);
            $this->fail('Certified result must be immutable.');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
        $this->assertSame($before, $certificate->fresh()->snapshot);
    }

    public function test_undeclared_detected_upgrade_cannot_skip_upgrade_checks_or_fifth_proof(): void
    {
        $inspection = $this->start();
        $this->ready($inspection);
        $this->proofs($inspection);
        $service = app(QcInspectionService::class);
        $service->update($inspection, ['final_configuration' => ['cpu' => 'Intel Core i5 8th Gen', 'ram_mb' => 16384, 'storage_gb' => 512, 'os' => 'Windows 11']], $this->technician);
        $this->assertSame(62, $inspection->checks()->where('applicable', true)->count());
        $this->assertValidation(fn () => $service->complete($inspection, $this->technician), 'checks.upgrade_ram.result');
        $service->passGroup($inspection, 'Upgrade Verification', $this->technician);
        $this->assertValidation(fn () => $service->complete($inspection, $this->technician), 'evidence.upgrade');
        $this->proofs($inspection, ['upgrade']);
        $certificate = $service->complete($inspection, $this->technician);
        $this->assertCount(5, $certificate->snapshot['evidence']);
        $this->assertSame(8192, $certificate->snapshot['original']['ram_mb']);
        $this->assertSame(16384, $certificate->snapshot['final']['ram_mb']);
    }

    public function test_draft_order_editing_keeps_working_and_qc_retains_historical_reference(): void
    {
        $data = new SaveAndReserveOrderData($this->warehouse->id, null, null, now()->toDateString(), $this->owner->employee->id, null, [new OrderItemData($this->product->id, 1, '250.00')], (string) str()->uuid());
        $order = app(OrderService::class)->saveDraft($data, $this->owner);
        $inspection = $this->start(['order_item_id' => $order->items->sole()->id]);
        app(OrderService::class)->saveDraft($data, $this->owner, $order);
        $this->assertSame($order->reference, $inspection->fresh()->order_snapshot['reference']);
        $this->assertDatabaseCount('inventory_reservations', 0);
    }

    public function test_reinspection_preserves_prior_history_and_disables_old_verified_label_until_new_completion(): void
    {
        [$inspection, $certificate] = $this->certified();
        $original = $certificate->snapshot;
        $new = app(QcInspectionService::class)->reopen($inspection, 'Recheck after correction', $this->owner);
        $this->assertSame(2, $new->version);
        $this->assertSame($this->technician->id, $new->technician_user_id);
        $this->assertNull($new->grade);
        $this->assertSame($inspection->device_id, $new->device_id);
        $this->assertSame($inspection->original_configuration, $new->original_configuration);
        $this->assertFalse($certificate->isCurrent());
        $this->assertValidation(fn () => app(QcDocumentService::class)->labels([$inspection->id], $this->owner), 'record');
        $this->assertStringContainsString('HISTORICAL / SUPERSEDED', view('qc.certificate', app(QcDocumentService::class)->data($certificate))->render());
        $this->assertStringStartsWith('%PDF', app(QcDocumentService::class)->pdf($inspection, $this->owner)->output());
        $this->ready($new, $this->owner);
        $this->proofs($new, actor: $this->owner);
        $second = app(QcInspectionService::class)->complete($new, $this->owner);
        $this->assertTrue($second->isCurrent());
        $this->assertNotSame($certificate->public_token, $second->public_token);
        $this->assertSame($original, $certificate->fresh()->snapshot);
        $this->assertSame($certificate->snapshot['reference'], $second->snapshot['reference']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'qc.reopened']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'qc.superseded']);
    }

    public function test_public_passport_and_evidence_exclude_internal_private_and_financial_data(): void
    {
        $inspection = $this->start();
        $this->ready($inspection);
        $this->proofs($inspection);
        $sameQcInternal = app(QcEvidenceService::class)->upload($inspection, UploadedFile::fake()->image('private-same-qc.jpg'), 'additional', false, $this->technician);
        $certificate = app(QcInspectionService::class)->complete($inspection, $this->technician);
        $this->assertNotContains($sameQcInternal->public_id, $certificate->snapshot['evidence']);
        $this->get(route('qc.evidence.public', ['token' => $certificate->public_token, 'id' => $sameQcInternal->public_id]))->assertNotFound();
        $page = $this->get(route('qc.verify', $certificate->public_token))->assertOk()->assertSee('QC VERIFIED')->assertSee('56 applicable checks')->assertDontSee('PRIVATE_INTERNAL_SENTINEL')->assertDontSee($this->technician->email)->assertDontSee('original_path')->assertDontSee('average_cost')->assertDontSee('customer_phone');
        $proof = $inspection->evidence()->where('kind', 'serial')->sole();
        $response = $this->get(route('qc.evidence.public', ['token' => $certificate->public_token, 'id' => $proof->public_id, 'original' => 1]))->assertOk();
        $this->assertSame(Storage::disk('local')->get($proof->customer_path), $response->getContent());
        $this->assertNotSame(Storage::disk('local')->get($proof->original_path), $response->getContent());
        $internalInspection = $this->start(['serial' => 'INTERNAL-DEVICE']);
        $internal = app(QcEvidenceService::class)->upload($internalInspection, UploadedFile::fake()->image('private.jpg'), 'additional', false, $this->technician);
        $this->get(route('qc.evidence.public', ['token' => $certificate->public_token, 'id' => $internal->public_id]))->assertNotFound();
        $this->actingAs($this->technician)->get(route('qc.evidence.internal', ['evidence' => $proof->id, 'original' => 1]))->assertForbidden();
        $this->actingAs($this->owner)->get(route('qc.evidence.internal', ['evidence' => $proof->id, 'original' => 1]))->assertOk();
    }

    public function test_exact_public_lookup_tokens_and_rate_limit(): void
    {
        [$inspection, $certificate] = $this->certified();
        $url = app(QcDocumentService::class)->url($certificate);
        $this->assertMatchesRegularExpression('/\/[a-f0-9]{64}$/', $url);
        $this->get(route('qc.search', ['q' => $inspection->device->serial]))->assertRedirect($url);
        $this->get(route('qc.search', ['q' => $inspection->device->reference]))->assertRedirect($url);
        $this->get('/verify/qc/1')->assertNotFound();
        $this->get('/verify/qc/'.str_repeat('0', 64))->assertNotFound();
        foreach (['%', '*', '5CG%', '5CG'] as $query) {
            $this->get(route('qc.search', ['q' => $query]))->assertOk()->assertSee('No verified QC record found.');
        }
        $this->get(route('qc.search', ['q' => ['unexpected']]))->assertOk()->assertSee('No verified QC record found.');
    }

    public function test_public_search_is_rate_limited(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->get(route('qc.search', ['q' => 'UNKNOWN']))->assertOk();
        }
        $this->get(route('qc.search', ['q' => 'UNKNOWN']))->assertStatus(429);
    }

    public function test_single_certificate_labels_bulk_reprint_and_audit_keep_identity(): void
    {
        [$inspection, $certificate] = $this->certified(['special_requirement' => '16GB RAM / 512GB storage']);
        $document = app(QcDocumentService::class);
        $pdf = $document->pdf($inspection, $this->technician)->output();
        $this->assertStringStartsWith('%PDF', $pdf);
        $label = $document->labels([$inspection->id], $this->technician);
        $again = $document->labels([$inspection->id], $this->technician);
        $this->assertSame($label[0]['verificationUrl'], $again[0]['verificationUrl']);
        $this->assertSame($label[0]['qr'], $again[0]['qr']);
        $qrBytes = base64_decode(explode(',', $label[0]['qr'], 2)[1]);
        $this->assertSame($label[0]['verificationUrl'], (new QRCode)->readFromBlob($qrBytes)->data);
        $this->assertSame($certificate->public_token, $inspection->certificate->public_token);
        [$other, $otherCertificate] = $this->certified(['serial' => 'OTHER-DEVICE']);
        $labels = $document->labels([$inspection->id, $other->id], $this->technician);
        $this->assertCount(2, $labels);
        $this->assertNotSame($labels[0]['verificationUrl'], $labels[1]['verificationUrl']);
        $html = view('qc.labels', ['labels' => $labels])->render();
        $this->assertSame(2, substr_count($html, '<section class="label">'));
        $this->assertStringContainsString('16', $html);
        $this->assertStringContainsString('512', $html);
        $this->assertStringNotContainsString('PRIVATE_INTERNAL_SENTINEL', $html);
        $this->assertStringNotContainsString('QC Manager Approval', view('qc.certificate', $document->data($certificate))->render());
        $this->assertDatabaseHas('activity_logs', ['event' => 'qc.certificate_printed']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'qc.label_printed']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'qc.bulk_labels_printed']);
    }

    public function test_pending_in_progress_and_failed_cannot_print_verified_and_bulk_is_all_or_nothing(): void
    {
        $inspection = $this->start();
        foreach (['pending', 'in_progress', 'rework_required'] as $state) {
            $inspection->update(['status' => $state]);
            $this->assertValidation(fn () => app(QcDocumentService::class)->labels([$inspection->id], $this->technician), 'record');
            $this->assertValidation(fn () => app(QcDocumentService::class)->pdf($inspection, $this->technician), 'record');
        }
        [$good] = $this->certified(['serial' => 'GOOD-DEVICE']);
        $count = DB::table('activity_logs')->where('event', 'qc.label_printed')->count();
        $this->assertValidation(fn () => app(QcDocumentService::class)->labels([$good->id, $inspection->id], $this->technician), 'record');
        $this->assertSame($count, DB::table('activity_logs')->where('event', 'qc.label_printed')->count());
    }

    public function test_technician_record_scope_and_direct_document_scope_cannot_be_bypassed(): void
    {
        [$inspection] = $this->certified();
        $other = $this->actor(EmployeeRole::Staff);
        foreach ([QcPermission::View, QcPermission::PrintLabel, QcPermission::PrintCertificate] as $permission) {
            $this->grant($permission, $other);
        }
        $this->assertFalse(app(QcInspectionService::class)->visible($other)->whereKey($inspection->id)->exists());
        $this->actingAs($other)->get(QcInspectionResource::getUrl('view', ['record' => $inspection]))->assertNotFound();
        $this->actingAs($other)->get(route('qc.certificate', $inspection))->assertForbidden();
        $this->actingAs($other)->get(route('qc.labels', ['ids' => [$inspection->id]]))->assertForbidden();
    }

    public function test_completed_only_permission_can_finalize_ready_job_from_view_without_edit_permission(): void
    {
        $inspection = $this->start();
        $this->ready($inspection);
        $this->proofs($inspection);
        app(EmployeePermissionOverrideService::class)->change($this->technician->employee, QcPermission::Update->value, EmployeePermissionEffect::Deny, 'Finalize only', $this->owner);
        Livewire::actingAs($this->technician)->test(ViewQcInspection::class, ['record' => $inspection->id])->callAction('completeQc')->assertHasNoActionErrors();
        $this->assertSame('completed', $inspection->fresh()->status->value);
    }

    public function test_product_sku_search_cannot_escape_technician_record_scope(): void
    {
        $own = $this->start();
        $otherProduct = Product::factory()->create();
        $other = app(QcInspectionService::class)->start($this->startData(['product_id' => $otherProduct->id, 'serial' => 'FOREIGN-DEVICE']), $this->owner);
        $component = Livewire::actingAs($this->technician)->test(ListQcInspections::class)->searchTable($otherProduct->sku);
        $this->assertCount(0, $component->instance()->getTableRecords());
        $component->searchTable($this->product->sku);
        $this->assertSame([$own->id], $component->instance()->getTableRecords()->modelKeys());
    }

    public function test_selected_completed_rows_build_bulk_label_url(): void
    {
        [$inspection] = $this->certified();
        $component = Livewire::actingAs($this->technician)->test(ListQcInspections::class)->set('selectedTableRecords', [$inspection->id]);
        $action = $component->instance()->getTable()->getBulkAction('printSelectedLabels');
        $this->assertSame(route('qc.labels', ['ids' => [$inspection->id]]), $action->getUrl());
    }

    public function test_certified_history_blocks_migration_rollback(): void
    {
        $this->certified();
        $migration = require database_path('migrations/2026_10_06_090000_create_qc_device_passports.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_customer_polish_exposes_only_snapshot_derivatives_with_correct_metadata_and_no_stock_mutation(): void
    {
        $inspection = $this->start();
        $this->ready($inspection);
        $this->proofs($inspection);
        $internal = app(QcEvidenceService::class)->upload($inspection, UploadedFile::fake()->image('private.jpg'), 'additional', false, $this->technician);
        $certificate = app(QcInspectionService::class)->complete($inspection, $this->technician);
        $before = $this->stockSnapshot();
        $product = $this->product->fresh()->toArray();
        $snapshot = $certificate->snapshot;
        $fingerprint = app(QcDocumentService::class)->fingerprint($certificate);
        $page = $this->get(route('qc.verify', $certificate->public_token))->assertOk()
            ->assertSee('CURRENT / VERIFIED')->assertSee('Download Certificate PDF')->assertSee('Print Certificate')
            ->assertSee('Certificate Fingerprint')->assertSee($fingerprint)->assertSee('Valid only when the QR code resolves to the matching TPZ QC ID and certificate version.')
            ->assertSee('class="watermarks"', false)->assertSee('<dialog', false)->assertSee('data-viewer-next', false)
            ->assertDontSee($internal->public_id)->assertDontSee($internal->original_path)->assertDontSee($internal->customer_path)
            ->assertDontSee('PRIVATE_INTERNAL_SENTINEL')->assertDontSee('original_path')->assertDontSee('customer_path');
        $dom = new \DOMDocument;
        @$dom->loadHTML($page->getContent());
        $xpath = new \DOMXPath($dom);
        $buttons = $xpath->query('//button[@data-evidence]');
        $this->assertCount(4, $buttons);
        foreach ($inspection->evidence()->where('customer_visible', true)->get() as $index => $proof) {
            $button = $buttons->item($index);
            $this->assertSame(QcEvidenceService::KINDS[$proof->kind], $button->getAttribute('data-kind'));
            $this->assertSame(app(BusinessTimezone::class)->format($proof->uploaded_at, 'd M Y H:i:s T'), $button->getAttribute('data-uploaded'));
            $this->assertSame($snapshot['reference'].' · v1', $button->getAttribute('data-reference'));
            $this->assertSame(route('qc.evidence.public', ['token' => $certificate->public_token, 'id' => $proof->public_id]), $button->getElementsByTagName('img')->item(0)->getAttribute('src'));
            $page->assertDontSee($proof->original_path)->assertDontSee($proof->customer_path);
        }
        $this->get(route('qc.certificate.public', $certificate->public_token))->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertDownload($snapshot['reference'].'-v1.pdf');
        $html = view('qc.certificate', app(QcDocumentService::class)->data($certificate))->render();
        foreach ([$fingerprint, 'TECH POINT ZONE', 'watermark-top', 'watermark-middle', 'watermark-bottom', 'Verification QR', 'Alterations invalidate this document', 'Valid only when the QR code resolves'] as $content) {
            $this->assertStringContainsString($content, $html);
        }
        $this->assertStringNotContainsString($internal->public_id, $html);
        $this->assertStringNotContainsString('PRIVATE_INTERNAL_SENTINEL', $html);
        $this->assertSame($snapshot, $certificate->fresh()->snapshot);
        $this->assertSame($before, $this->stockSnapshot());
        $this->assertSame($product, $this->product->fresh()->toArray());
    }

    public function test_public_pdf_requires_exact_valid_token_and_is_rate_limited(): void
    {
        [$inspection, $certificate] = $this->certified();
        $this->get('/verify/qc/1/certificate')->assertNotFound();
        $this->get(route('qc.certificate.public', str_repeat('0', 64)))->assertNotFound();
        $url = route('qc.certificate.public', $certificate->public_token);
        for ($i = 0; $i < 9; $i++) {
            $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        }
        $this->get($url)->assertStatus(429);
    }

    public function test_fingerprint_and_historical_download_are_stable_across_reqc_without_snapshot_changes(): void
    {
        [$inspection, $first] = $this->certified();
        $document = app(QcDocumentService::class);
        $snapshot = $first->snapshot;
        $fingerprint = $document->fingerprint($first);
        $this->assertMatchesRegularExpression('/^[A-F0-9]{4}(?:-[A-F0-9]{4}){3}$/', $fingerprint);
        $this->assertSame($fingerprint, $document->fingerprint($first->fresh()));
        $reordered = clone $first;
        $reordered->snapshot = array_reverse($snapshot, true);
        $this->assertSame($fingerprint, $document->fingerprint($reordered));
        $new = app(QcInspectionService::class)->reopen($inspection, 'New verified version', $this->owner);
        $this->get(route('qc.verify', $first->public_token))->assertSee('SUPERSEDED / HISTORICAL')->assertSee('Reinspection is in progress');
        $this->ready($new, $this->owner);
        $this->proofs($new, actor: $this->owner);
        $second = app(QcInspectionService::class)->complete($new, $this->owner);
        $this->assertNotSame($fingerprint, $document->fingerprint($second));
        $this->get(route('qc.verify', $first->public_token))->assertSee('SUPERSEDED / HISTORICAL')->assertSee($document->url($second))->assertSee($fingerprint);
        $this->get(route('qc.verify', $second->public_token))->assertSee('CURRENT / VERIFIED')->assertSee($document->fingerprint($second));
        $this->get(route('qc.certificate.public', $first->public_token))->assertOk()->assertDownload($snapshot['reference'].'-v1.pdf');
        $this->assertSame($snapshot, $first->fresh()->snapshot);
        $this->assertSame($fingerprint, $document->fingerprint($first->fresh()));
    }

    public function test_thermal_labels_keep_distinct_device_qr_mapping_and_concise_long_titles(): void
    {
        $this->product->update(['name' => str_repeat('Detailed customer marketplace laptop title ', 15), 'model' => str_repeat('Long laptop model ', 12)]);
        [$one, $first] = $this->certified(['serial' => str_repeat('A', 100)]);
        [$two, $second] = $this->certified(['serial' => 'SECOND-LABEL-SERIAL']);
        $labels = app(QcDocumentService::class)->labels([$one->id, $two->id], $this->technician);
        $html = view('qc.labels', ['labels' => $labels])->render();
        $this->assertStringContainsString('@page{size:100mm 50mm;margin:0}', $html);
        $this->assertStringContainsString('break-after:page', $html);
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $sections = $xpath->query('//section[@class="label"]');
        $this->assertCount(2, $sections);
        foreach ([$first, $second] as $index => $certificate) {
            $section = $sections->item($index);
            $this->assertStringContainsString($certificate->snapshot['serial'], $section->textContent);
            $this->assertStringContainsString($certificate->snapshot['reference'].' · v1', $section->textContent);
            $this->assertStringContainsString('TECH POINT ZONE', $section->textContent);
            $this->assertStringContainsString('Scan to Verify QC', $section->textContent);
            $title = $xpath->query('.//div[@class="title"]', $section)->item(0)->textContent;
            $this->assertLessThanOrEqual(54, mb_strlen($title));
            $qr = $xpath->query('.//img[@class="qr"]', $section)->item(0)->getAttribute('src');
            $this->assertSame(app(QcDocumentService::class)->url($certificate), (new QRCode)->readFromBlob(base64_decode(explode(',', $qr, 2)[1]))->data);
        }
    }

    private function actor(EmployeeRole $role): User
    {
        $employee = Employee::factory()->role($role)->create();
        $email = 'qc-'.str()->random(12).'@techpointzone.com';
        $employee->user()->update(['email' => $email]);
        $employee->update(['email' => $email]);

        return $employee->refresh()->user;
    }

    private function grant(QcPermission $permission, ?User $actor = null): void
    {
        app(EmployeePermissionOverrideService::class)->change(($actor ?? $this->technician)->employee, $permission->value, EmployeePermissionEffect::Allow, 'QC technician permission', $this->owner);
    }

    private function startData(array $changes = []): array
    {
        return $changes + ['product_id' => $this->product->id, 'serial' => '5CG9117JYW', 'warehouse_id' => $this->warehouse->id, 'features' => [], 'idempotency_key' => (string) str()->uuid()];
    }

    private function start(array $changes = []): QcInspection
    {
        return app(QcInspectionService::class)->start($this->startData($changes), $this->technician);
    }

    private function ready(QcInspection $inspection, ?User $actor = null): void
    {
        $checks = $inspection->checks()->get()->where('applicable', true)->mapWithKeys(fn ($check) => [$check->check_key => ['result' => 'pass'] + ($check->definition['measurement'] ? ['measurement' => $check->check_key === 'temperature' ? 70 : 90] : [])])->all();
        $requested = $inspection->requested_configuration ?? [];
        app(QcInspectionService::class)->update($inspection, ['checks' => $checks, 'grade' => 'A', 'final_configuration' => ['cpu' => 'Intel Core i5 8th Gen', 'ram_mb' => $requested['target_ram_mb'] ?? (isset($requested['special_requirement']) ? 16384 : 8192), 'storage_gb' => $requested['target_storage_total_gb'] ?? (isset($requested['special_requirement']) ? 512 : 256), 'os' => 'Windows 11'], 'public_remarks' => 'Verified device', 'internal_remarks' => 'PRIVATE_INTERNAL_SENTINEL'], $actor ?? $this->technician);
    }

    private function proofs(QcInspection $inspection, array $kinds = ['serial', 'physical', 'display', 'system'], ?User $actor = null): void
    {
        foreach ($kinds as $kind) {
            app(QcEvidenceService::class)->upload($inspection, UploadedFile::fake()->image($kind.'.jpg', 800, 600), $kind, true, $actor ?? $this->technician);
        }
    }

    private function certified(array $changes = []): array
    {
        $inspection = $this->start($changes);
        $this->ready($inspection);
        $this->proofs($inspection, ['serial', 'physical', 'display', 'system', ...($inspection->requested_configuration ? ['upgrade'] : [])]);

        return [$inspection, app(QcInspectionService::class)->complete($inspection, $this->technician)];
    }

    private function assertValidation(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Expected validation for '.$field);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function stockSnapshot(): array
    {
        return collect(['product_inventories', 'inventory_allocation_balances', 'inventory_allocation_events', 'inventory_reservations', 'stock_movements', 'orders', 'order_items'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }
}
