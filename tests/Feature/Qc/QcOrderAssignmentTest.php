<?php

namespace Tests\Feature\Qc;

use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\FulfillOrder;
use App\Actions\Orders\SaveAndReserveOrder;
use App\DTOs\Orders\CancelOrderData;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\ProductCondition;
use App\Enums\QcPermission;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\WebSalesOrders\Pages\ViewWebSalesOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemUpgradeSelection;
use App\Models\Product;
use App\Models\QcCertificate;
use App\Models\QcInspection;
use App\Models\QcOrderAssignment;
use App\Models\SalesConfiguration;
use App\Models\UpgradeRecipe;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ActivityLogger;
use App\Services\Authorization\EmployeePermissionOverrideService;
use App\Services\Orders\OrderAmendmentService;
use App\Services\Orders\OrderService;
use App\Services\Qc\QcDocumentService;
use App\Services\Qc\QcEvidenceService;
use App\Services\Qc\QcInspectionService;
use App\Services\Qc\QcOrderAssignmentService;
use App\Services\Qc\RenewedQcDispatchService;
use App\Services\Responsibilities\ResponsibilityAssignmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\Process\Process;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class QcOrderAssignmentTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    private array $foundation;

    private User $owner;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->foundation = $this->responsibilityFoundation(50);
        $this->owner = $this->foundation['owner'];
        $this->product = $this->foundation['product'];
        $this->product->update(['condition' => ProductCondition::Renewed, 'model' => 'EliteBook 840', 'processor' => 'Intel Core i5', 'ram' => '8GB', 'storage' => '256GB', 'touch_screen' => false]);
        $this->warehouse = $this->foundation['inventory']->warehouse;
    }

    public function test_current_certificate_assigns_exact_unit_and_retry_is_idempotent_without_business_mutation(): void
    {
        $item = $this->order()->items->sole();
        $certificate = $this->certify();
        $before = $this->businessSnapshot();
        $assignment = $this->assign($item, $certificate);
        $again = $this->assign($item, $certificate);
        $this->assertSame($assignment->id, $again->id);
        $this->assertSame($item->order_id, $assignment->order_id);
        $this->assertSame($certificate->id, $assignment->qc_certificate_id);
        $this->assertSame(1, $assignment->certificate_version);
        $this->assertSame($certificate->device_id, $assignment->active_device_id);
        $this->assertDatabaseCount('qc_order_assignments', 1);
        $this->assertSame(1, DB::table('activity_logs')->where('event', 'qc.order_device_assigned')->count());
        $this->assertSame($before, $this->businessSnapshot());
        $log = DB::table('activity_logs')->where('event', 'qc.order_device_assigned')->sole();
        $this->assertSame('qc_order_assignment', $log->subject_type);
        $this->assertStringNotContainsString($certificate->public_token, $log->properties);
        $this->assertStringNotContainsString('original_path', $log->properties);
    }

    public function test_missing_or_incomplete_qc_certificate_is_rejected(): void
    {
        $item = $this->order()->items->sole();
        $this->reject(fn () => app(QcOrderAssignmentService::class)->assign($item, 999999, $this->owner), 'certificate_id');
        $complete = $this->certify();
        $pending = $this->start('PENDING-DEVICE');
        $invalid = QcCertificate::query()->create(['inspection_id' => $pending->id, 'device_id' => $pending->device_id, 'version' => 1, 'public_token' => bin2hex(random_bytes(32)), 'snapshot' => $complete->snapshot, 'certified_at' => now()]);
        $this->reject(fn () => $this->assign($item, $invalid), 'certificate_id');
        $this->assertDatabaseCount('qc_order_assignments', 0);
    }

    public function test_pending_reqc_and_superseded_certificate_are_rejected_without_substitution(): void
    {
        $item = $this->order()->items->sole();
        $first = $this->certify();
        $assignment = $this->assign($item, $first);
        $snapshot = $first->snapshot;
        $new = app(QcInspectionService::class)->reopen($first->inspection, 'Recheck unit', $this->owner);
        $this->reject(fn () => $this->assign($item, $first), 'certificate_id');
        Livewire::actingAs($this->owner)->test(ViewOrder::class, ['record' => $item->order_id])->assertSee('not fulfilment-ready');
        $second = $this->finish($new);
        $this->reject(fn () => $this->assign($item, $first), 'certificate_id');
        $this->assertSame($first->id, $assignment->fresh()->qc_certificate_id);
        $this->assertSame(1, $assignment->fresh()->certificate_version);
        $this->assertSame($snapshot, $first->fresh()->snapshot);
        app(QcOrderAssignmentService::class)->release($assignment, 'Reassign current QC version', $this->owner);
        $replacement = $this->assign($item, $second);
        $this->assertSame(2, $replacement->certificate_version);
        $this->assertDatabaseCount('qc_order_assignments', 2);
    }

    public function test_wrong_product_and_warehouse_are_rejected_and_not_candidates(): void
    {
        $item = $this->order()->items->sole();
        $wrongProduct = $this->certify('OTHER-PRODUCT', ['product_id' => Product::factory()->create(['ram' => '8GB', 'storage' => '256GB'])->id]);
        $wrongLocation = $this->certify('OTHER-WAREHOUSE', ['warehouse_id' => Warehouse::factory()->create()->id]);
        foreach ([$wrongProduct, $wrongLocation] as $certificate) {
            $this->reject(fn () => $this->assign($item, $certificate), 'certificate_id');
        }
        $this->assertSame(0, app(QcOrderAssignmentService::class)->candidates($item, $this->owner)->count());
    }

    public function test_cancelled_order_cannot_receive_devices_but_can_release_existing_history(): void
    {
        $order = $this->order();
        $certificate = $this->certify();
        $assignment = $this->assign($order->items->sole(), $certificate);
        app(CancelOrder::class)->handle($order, new CancelOrderData('Order cancelled', (string) str()->uuid()), $this->owner);
        $this->reject(fn () => $this->assign($order->items->sole(), $certificate), 'order_item_id');
        $before = $this->businessSnapshot();
        $released = app(QcOrderAssignmentService::class)->release($assignment, 'Order cancelled', $this->owner);
        $this->assertNotNull($released->released_at);
        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_draft_assignment_is_blocked_and_existing_draft_editing_remains_unchanged(): void
    {
        $data = $this->orderData();
        $order = app(OrderService::class)->saveDraft($data, $this->owner);
        $certificate = $this->certify();
        $this->reject(fn () => $this->assign($order->items->sole(), $certificate), 'order_item_id');
        app(OrderService::class)->saveDraft($data, $this->owner, $order);
        $this->assertDatabaseCount('qc_order_assignments', 0);
        Livewire::actingAs($this->owner)->test(ViewOrder::class, ['record' => $order->id])->assertActionHidden('assignQcDevice');
    }

    public function test_same_device_cannot_be_assigned_to_another_order_or_item(): void
    {
        $first = $this->order()->items->sole();
        $second = $this->order()->items->sole();
        $certificate = $this->certify();
        $this->assign($first, $certificate);
        $this->reject(fn () => $this->assign($second, $certificate), 'certificate_id');
        $this->assertDatabaseCount('qc_order_assignments', 1);
        $this->assertSame(0, app(QcOrderAssignmentService::class)->candidates($second, $this->owner)->count());
    }

    public function test_multiple_units_are_supported_but_ordered_quantity_cannot_be_exceeded(): void
    {
        $item = $this->order(2)->items->sole();
        $one = $this->certify('UNIT-ONE');
        $two = $this->certify('UNIT-TWO');
        $three = $this->certify('UNIT-THREE');
        $this->assign($item, $one);
        $this->assign($item, $two);
        $this->reject(fn () => $this->assign($item, $three), 'order_item_id');
        $this->assertSame(2, $item->qcAssignments()->active()->count());
    }

    public function test_structured_ram_and_storage_mismatch_are_rejected_and_matching_upgrade_is_accepted(): void
    {
        $item = $this->order()->items->sole();
        $this->upgrade($item);
        $ramMismatch = $this->certify('RAM-MISMATCH', final: ['ram_mb' => 8192, 'storage_gb' => 512]);
        $storageMismatch = $this->certify('STORAGE-MISMATCH', final: ['ram_mb' => 16384, 'storage_gb' => 256]);
        $match = $this->certify('CONFIG-MATCH', final: ['ram_mb' => 16384, 'storage_gb' => 512]);
        $this->reject(fn () => $this->assign($item, $ramMismatch), 'certificate_id');
        $this->reject(fn () => $this->assign($item, $storageMismatch), 'certificate_id');
        $this->assertSame([$match->id], app(QcOrderAssignmentService::class)->candidates($item, $this->owner)->pluck('id')->all());
        $this->assertSame($match->id, $this->assign($item, $match)->qc_certificate_id);
    }

    public function test_release_requires_permission_and_reason_retains_history_and_allows_safe_reassignment(): void
    {
        $item = $this->order()->items->sole();
        $certificate = $this->certify();
        $assignment = $this->assign($item, $certificate);
        $staff = $this->foundation['employee']->user;
        $this->assertDenied(fn () => app(QcOrderAssignmentService::class)->release($assignment, 'Packing correction', $staff));
        $this->reject(fn () => app(QcOrderAssignmentService::class)->release($assignment, ' ', $this->owner), 'release_reason');
        $before = $this->businessSnapshot();
        $released = app(QcOrderAssignmentService::class)->release($assignment, 'Packing correction', $this->owner);
        $this->assertSame($certificate->id, $released->qc_certificate_id);
        $this->assertSame('Packing correction', $released->release_reason);
        $this->assertNull($released->active_device_id);
        $this->assertNotNull($released->released_at);
        app(QcOrderAssignmentService::class)->release($released, 'Browser retry', $this->owner);
        $this->assertSame(1, DB::table('activity_logs')->where('event', 'qc.order_device_released')->count());
        $otherItem = $this->order()->items->sole();
        $before = $this->businessSnapshot();
        $new = $this->assign($otherItem, $certificate);
        $this->assertNotSame($assignment->id, $new->id);
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertDatabaseCount('qc_order_assignments', 2);
    }

    public function test_assignment_and_released_history_cannot_be_rewritten_or_deleted(): void
    {
        $assignment = $this->assign($this->order()->items->sole(), $this->certify());
        foreach ([fn () => $assignment->update(['certificate_version' => 99]), fn () => $assignment->delete()] as $operation) {
            try {
                $operation();
                $this->fail('Immutable assignment was changed.');
            } catch (\LogicException) {
                $this->assertTrue(true);
            }
        }
        $released = app(QcOrderAssignmentService::class)->release($assignment->fresh(), 'Packing correction', $this->owner);
        try {
            $released->update(['release_reason' => 'Rewrite history']);
            $this->fail('Release history changed.');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
        $this->assertSame(1, $released->fresh()->certificate_version);
    }

    public function test_renewed_fulfilment_requires_dispatch_readiness_and_shipped_assignment_history_is_locked(): void
    {
        $order = $this->order();
        try {
            app(FulfillOrder::class)->handle($order, (string) str()->uuid(), $this->owner);
            $this->fail('Renewed fulfillment must remain blocked until a current QC device is assigned.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('qc', $exception->errors());
        }
        $certificate = $this->certify();
        $assignment = $this->assign($order->items->sole(), $certificate);
        $this->assertSame('ready', app(RenewedQcDispatchService::class)->readiness($order)['status']);
        app(RenewedQcDispatchService::class)->ship($order, (string) str()->uuid(), $this->owner);
        $this->reject(fn () => app(QcOrderAssignmentService::class)->release($assignment, 'Reassign shipped unit', $this->owner), 'assignment_id');
        $this->reject(fn () => $this->assign($order->items->sole(), $certificate), 'order_item_id');
        $unassigned = $this->order();
        try {
            app(FulfillOrder::class)->handle($unassigned, (string) str()->uuid(), $this->owner);
            $this->fail('Unassigned Renewed stock must not bypass the dispatch gate.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('qc', $exception->errors());
        }
        $this->assertSame('reserved', $unassigned->fresh()->status->value);
        $this->assertNull($assignment->fresh()->released_at);
    }

    public function test_quantity_reduction_cannot_orphan_assigned_units_and_rolls_back_normally(): void
    {
        $order = $this->order(2);
        $item = $order->items->sole();
        $first = $this->assign($item, $this->certify('FIRST-UNIT'));
        $this->assign($item, $this->certify('SECOND-UNIT'));
        $before = $this->businessSnapshot();
        $data = ['reason' => 'Customer needs fewer units', 'idempotency_key' => (string) str()->uuid(), 'items' => [['id' => $item->id, 'quantity' => 1]]];
        $this->reject(fn () => app(OrderAmendmentService::class)->amend($order, $data, $this->owner), 'items');
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertDatabaseCount('order_amendments', 0);
        app(QcOrderAssignmentService::class)->release($first, 'Quantity amendment', $this->owner);
        app(OrderAmendmentService::class)->amend($order, $data, $this->owner);
        $this->assertSame(1, $item->fresh()->ordered_quantity);
        $this->assertSame(1, $item->fresh()->reservation->quantity);
    }

    public function test_staff_permissions_require_order_scope_and_qc_record_scope_without_implicit_manager_access(): void
    {
        $order = $this->order();
        $item = $order->items->sole();
        $certificate = $this->certify();
        $staff = $this->foundation['employee']->user;
        $this->assertDenied(fn () => app(QcOrderAssignmentService::class)->assign($item, $certificate->id, $staff));
        $this->grant($staff, [QcPermission::View, QcPermission::ViewOrderAssignments, QcPermission::AssignOrderDevice]);
        $this->assertDenied(fn () => app(QcOrderAssignmentService::class)->assign($item, $certificate->id, $staff));
        app(ResponsibilityAssignmentService::class)->create($this->assignmentData($this->foundation), $this->owner);
        $this->assertDenied(fn () => app(QcOrderAssignmentService::class)->assign($item, $certificate->id, $staff));
        $this->grant($staff, [QcPermission::ViewAll]);
        $this->assertSame($certificate->id, app(QcOrderAssignmentService::class)->assign($item, $certificate->id, $staff)->qc_certificate_id);
        $manager = $this->responsibilityUser(EmployeeRole::Manager);
        $this->assertFalse(app(QcOrderAssignmentService::class)->allows($manager, QcPermission::AssignOrderDevice, $order));
    }

    public function test_owner_admin_defaults_and_explicit_admin_denials_are_preserved(): void
    {
        $order = $this->order();
        $admin = $this->responsibilityUser(EmployeeRole::Admin);
        foreach ([QcPermission::ViewOrderAssignments, QcPermission::AssignOrderDevice, QcPermission::ReleaseOrderAssignment] as $permission) {
            $this->assertTrue(app(QcOrderAssignmentService::class)->allows($this->owner, $permission, $order));
            $this->assertTrue(app(QcOrderAssignmentService::class)->allows($admin, $permission, $order));
        }
        app(EmployeePermissionOverrideService::class)->change($admin->employee, QcPermission::AssignOrderDevice->value, EmployeePermissionEffect::Deny, 'No device assignment', $this->owner);
        $this->assertDenied(fn () => app(QcOrderAssignmentService::class)->assign($order->items->sole(), $this->certify()->id, $admin));
    }

    public function test_candidates_search_serial_reference_and_safe_tpz_qr_not_external_urls(): void
    {
        $item = $this->order()->items->sole();
        $certificate = $this->certify('SEARCH_SERIAL-001');
        $service = app(QcOrderAssignmentService::class);
        foreach ([$certificate->snapshot['serial'], $certificate->snapshot['reference'], app(QcDocumentService::class)->url($certificate)] as $value) {
            $this->assertSame($certificate->id, $service->resolveScan($item, $value, $this->owner)->id);
        }
        $this->assertSame([$certificate->id], $service->candidates($item, $this->owner, 'SEARCH_SERIAL')->pluck('id')->all());
        $url = app(QcDocumentService::class)->url($certificate);
        foreach (['https://evil.example/verify/qc/'.$certificate->public_token, $url.'?original=1', $url.'#fragment', str_replace('://', '://someone@', $url), '/verify/qc/'.$certificate->public_token] as $value) {
            $this->reject(fn () => $service->resolveScan($item, $value, $this->owner), 'certificate_id');
        }
    }

    public function test_livewire_assign_count_links_and_release_show_version_but_not_raw_token_text(): void
    {
        $order = $this->order(2);
        $certificate = $this->certify();
        $component = Livewire::actingAs($this->owner)->test(ViewOrder::class, ['record' => $order->id])
            ->assertSee('QC Devices: 0 / 2 Assigned')->callAction('assignQcDevice', ['order_item_id' => $order->items->sole()->id, 'certificate_id' => $certificate->id])->assertHasNoActionErrors()
            ->assertSee('QC Devices: 1 / 2 Assigned')->assertSee($certificate->snapshot['serial'])->assertSee($certificate->snapshot['reference']);
        $this->assertStringNotContainsString($certificate->public_token, strip_tags($component->html()));
        $assignment = QcOrderAssignment::query()->sole();
        $component->callAction('releaseQcAssignment', ['assignment_id' => $assignment->id, 'release_reason' => 'Packing correction'])->assertHasNoActionErrors()
            ->assertSee('QC Devices: 0 / 2 Assigned')->assertSee('Released assignment history')->assertSee('Packing correction');
    }

    public function test_livewire_tampered_other_order_item_or_ineligible_certificate_is_rejected_with_field_errors(): void
    {
        $order = $this->order();
        $other = $this->order();
        $certificate = $this->certify();
        Livewire::actingAs($this->owner)->test(ViewOrder::class, ['record' => $order->id])
            ->callAction('assignQcDevice', ['order_item_id' => $other->items->sole()->id, 'certificate_id' => $certificate->id])->assertHasActionErrors(['order_item_id']);
        $wrong = $this->certify('WRONG-LOCATION', ['warehouse_id' => Warehouse::factory()->create()->id]);
        Livewire::actingAs($this->owner)->test(ViewOrder::class, ['record' => $order->id])
            ->callAction('assignQcDevice', ['order_item_id' => $order->items->sole()->id, 'certificate_id' => $wrong->id])->assertHasActionErrors(['certificate_id']);
        $this->assertDatabaseCount('qc_order_assignments', 0);
    }

    public function test_livewire_qr_scan_selects_current_certificate_without_assigning_until_submit(): void
    {
        $order = $this->order();
        $certificate = $this->certify();
        $component = Livewire::actingAs($this->owner)->test(ViewOrder::class, ['record' => $order->id])->mountAction('assignQcDevice')->fillForm(['order_item_id' => $order->items->sole()->id]);
        $component->call('resolveQcAssignmentScan', app(QcDocumentService::class)->url($certificate))->assertHasNoErrors();
        $this->assertSame($certificate->id, (int) $component->instance()->mountedActions[0]['data']['certificate_id']);
        $this->assertDatabaseCount('qc_order_assignments', 0);
    }

    public function test_failed_scan_highlights_certificate_field_and_preserves_selected_order_item(): void
    {
        $order = $this->order();
        $component = Livewire::actingAs($this->owner)->test(ViewOrder::class, ['record' => $order->id])->mountAction('assignQcDevice')->fillForm(['order_item_id' => $order->items->sole()->id]);
        $component->call('resolveQcAssignmentScan', 'https://external.example/verify/qc/'.str_repeat('a', 64))->assertHasActionErrors(['certificate_id']);
        $this->assertSame($order->items->sole()->id, (int) $component->instance()->mountedActions[0]['data']['order_item_id']);
        $this->assertDatabaseCount('qc_order_assignments', 0);
    }

    public function test_migration_empty_up_down_is_safe_and_history_blocks_rollback(): void
    {
        $migration = require database_path('migrations/2026_10_06_120000_create_qc_order_assignments.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('qc_order_assignments'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('qc_order_assignments'));
        $this->assign($this->order()->items->sole(), $this->certify());
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_competing_assignments_cannot_double_assign_a_device_or_exceed_one_item_quantity(): void
    {
        $first = $this->order()->items->sole();
        $second = $this->order()->items->sole();
        $one = $this->certify('RACE-UNIT-ONE');
        $two = $this->certify('RACE-UNIT-TWO');
        $this->race([[$first->id, $one->id], [$second->id, $one->id]]);
        $this->race([[$first->id, $one->id], [$first->id, $two->id]]);
        $this->assertDatabaseCount('qc_order_assignments', 0); // Workers use copies only.
    }

    public function test_assignment_and_release_audit_failure_rolls_back_without_partial_history(): void
    {
        $item = $this->order()->items->sole();
        $certificate = $this->certify();
        $before = $this->businessSnapshot();
        $real = app(ActivityLogger::class);
        $broken = \Mockery::mock(ActivityLogger::class);
        $broken->shouldReceive('log')->once()->andThrow(new \RuntimeException('Synthetic audit failure'));
        app()->instance(ActivityLogger::class, $broken);
        try {
            $this->assign($item, $certificate);
            $this->fail('Assignment must roll back.');
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('qc_order_assignments', 0);
        }
        app()->instance(ActivityLogger::class, $real);
        $assignment = $this->assign($item, $certificate);
        $broken = \Mockery::mock(ActivityLogger::class);
        $broken->shouldReceive('log')->once()->andThrow(new \RuntimeException('Synthetic audit failure'));
        app()->instance(ActivityLogger::class, $broken);
        try {
            app(QcOrderAssignmentService::class)->release($assignment, 'Packing correction', $this->owner);
            $this->fail('Release must roll back.');
        } catch (\RuntimeException) {
            $this->assertNull($assignment->fresh()->released_at);
        }
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertSame(0, DB::table('activity_logs')->where('event', 'qc.order_device_released')->count());
    }

    public function test_qc_started_for_order_is_not_automatically_a_final_unit_assignment(): void
    {
        $item = $this->order()->items->sole();
        $certificate = $this->certify('ORDER-CONTEXT', ['order_item_id' => $item->id]);
        $this->assertSame($item->id, $certificate->inspection->order_item_id);
        $this->assertDatabaseCount('qc_order_assignments', 0);
        $this->assertSame($item->id, $this->assign($item, $certificate)->order_item_id);
    }

    public function test_staff_ui_keeps_assignment_hidden_until_explicit_permission_and_never_exposes_unscoped_orders(): void
    {
        $order = $this->order();
        $certificate = $this->certify();
        $staff = $this->foundation['employee']->user;
        app(ResponsibilityAssignmentService::class)->create($this->assignmentData($this->foundation), $this->owner);
        Livewire::actingAs($staff)->test(ViewOrder::class, ['record' => $order->id])->assertActionHidden('assignQcDevice')->assertSee('QC Unit Traceability')->assertDontSee($certificate->snapshot['serial']);
        $this->grant($staff, [QcPermission::View, QcPermission::ViewAll, QcPermission::ViewOrderAssignments, QcPermission::AssignOrderDevice]);
        Livewire::actingAs($staff)->test(ViewOrder::class, ['record' => $order->id])->assertActionVisible('assignQcDevice')
            ->callAction('assignQcDevice', ['order_item_id' => $order->items->sole()->id, 'certificate_id' => $certificate->id])->assertHasNoActionErrors()->assertSee('QC Devices: 1 / 1 Assigned');
        $unrelated = $this->responsibilityUser(EmployeeRole::Staff);
        $this->grant($unrelated, [QcPermission::View, QcPermission::ViewAll, QcPermission::ViewOrderAssignments, QcPermission::AssignOrderDevice]);
        $this->assertDenied(fn () => app(QcOrderAssignmentService::class)->history($order, $unrelated));
    }

    public function test_web_sales_reuses_the_same_assignment_service_and_traceability_ui(): void
    {
        $order = $this->order();
        $order->update(['web_sales_channel' => 'other', 'delivery_type' => 'shop_pickup', 'customer_name' => 'Synthetic Customer', 'customer_phone' => '0500000000']);
        $certificate = $this->certify();
        Livewire::actingAs($this->owner)->test(ViewWebSalesOrder::class, ['record' => $order->id])
            ->callAction('assignQcDevice', ['order_item_id' => $order->items->sole()->id, 'certificate_id' => $certificate->id])->assertHasNoActionErrors()->assertSee('QC Devices: 1 / 1 Assigned');
        $this->assertDatabaseCount('qc_order_assignments', 1);
    }

    public function test_mysql_ddl_has_short_explicit_identifiers_nullable_unique_active_key_and_no_triggers(): void
    {
        // Compile only: the PDO is isolated SQLite and no MySQL connection is opened.
        $connection = new MySqlConnection(new \PDO('sqlite::memory:'), 'qc_schema_fixture', '', ['engine' => 'InnoDB', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'version' => '8.4.0']);
        $connection->setSchemaGrammar(new MySqlGrammar($connection));
        $sql = [];
        Schema::shouldReceive('create')->once()->with('qc_order_assignments', \Mockery::type(\Closure::class))->andReturnUsing(function ($name, $callback) use ($connection, &$sql): void {
            $blueprint = new Blueprint($connection, $name);
            $blueprint->create();
            $callback($blueprint);
            $sql = $blueprint->toSql();
        });
        $migration = require database_path('migrations/2026_10_06_120000_create_qc_order_assignments.php');
        $migration->up();
        $ddl = implode("\n", $sql);
        $this->assertStringContainsString('`active_device_id` bigint unsigned null', $ddl);
        $this->assertStringContainsString('unique `qoa_active_device_uq`', $ddl);
        $this->assertSame(7, substr_count($ddl, 'on delete restrict'));
        $this->assertStringNotContainsString('trigger', strtolower($ddl));
        preg_match_all('/(?:constraint|(?:unique|index)) `([^`]+)`/i', $ddl, $names);
        $this->assertCount(11, $names[1]);
        foreach ($names[1] as $name) {
            $this->assertLessThanOrEqual(64, strlen($name));
        }
    }

    private function race(array $requests): void
    {
        $path = sys_get_temp_dir().'/tpz-qc-assignment-race-'.str()->uuid().'.sqlite';
        touch($path);
        $workers = [];
        $copy = null;
        try {
            $copy = new \PDO('sqlite:'.$path);
            $copy->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $copy->exec('PRAGMA foreign_keys=OFF');
            foreach (DB::select("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'") as $table) {
                $copy->exec($table->sql);
                foreach (DB::table($table->name)->get() as $record) {
                    $fields = array_keys((array) $record);
                    $statement = $copy->prepare('INSERT INTO "'.$table->name.'" ("'.implode('","', $fields).'") VALUES ('.implode(',', array_fill(0, count($fields), '?')).')');
                    $statement->execute(array_values((array) $record));
                }
            }
            // Preserve explicit UNIQUE indexes, especially the active-device key.
            foreach (DB::select("SELECT sql FROM sqlite_master WHERE type='index' AND sql IS NOT NULL") as $index) {
                $copy->exec($index->sql);
            }
            $copy->exec('PRAGMA journal_mode=WAL');
            $statement = null;
            $copy = null;
            foreach ($requests as $slot => [$itemId, $certificateId]) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/QcOrderAssignmentWorker.php'), $path, (string) $itemId, (string) $certificateId, (string) $this->owner->id, (string) $slot], base_path(), timeout: 45);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 20;
            while ((! is_file($path.'.ready.0') || ! is_file($path.'.ready.1')) && microtime(true) < $deadline) {
                foreach ($workers as $slot => $worker) {
                    if (! is_file($path.'.ready.'.$slot) && ! $worker->isRunning()) {
                        break 2;
                    }
                }
                usleep(10000);
            }
            foreach ($workers as $slot => $worker) {
                $this->assertFileExists($path.'.ready.'.$slot, 'Worker '.$slot.' exited '.$worker->getExitCode().' before readiness; diagnostics: '.$worker->getErrorOutput());
            }
            touch($path.'.go');
            $outcomes = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $outcomes[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR)['outcome'];
            }
            sort($outcomes);
            $this->assertSame(['assigned', 'rejected'], $outcomes);
            $copy = new \PDO('sqlite:'.$path);
            $this->assertSame(1, (int) $copy->query('SELECT COUNT(*) FROM qc_order_assignments WHERE released_at IS NULL')->fetchColumn());
            $this->assertSame(1, (int) $copy->query("SELECT COUNT(*) FROM activity_logs WHERE event='qc.order_device_assigned'")->fetchColumn());
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            $statement = null;
            $copy = null;
            foreach ([$path, $path.'-wal', $path.'-shm', $path.'-journal', $path.'.go', $path.'.ready.0', $path.'.ready.1'] as $temporary) {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        }
    }

    private function orderData(int $quantity = 1): SaveAndReserveOrderData
    {
        return new SaveAndReserveOrderData($this->warehouse->id, null, null, now()->toDateString(), $this->owner->employee->id, null, [new OrderItemData($this->product->id, $quantity, '500.00')], (string) str()->uuid());
    }

    private function order(int $quantity = 1): Order
    {
        return app(SaveAndReserveOrder::class)->handle($this->orderData($quantity), $this->owner);
    }

    private function start(string $serial, array $changes = []): QcInspection
    {
        return app(QcInspectionService::class)->start($changes + ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'serial' => $serial, 'features' => [], 'idempotency_key' => (string) str()->uuid()], $this->owner);
    }

    private function certify(string $serial = 'ASSIGNMENT-UNIT', array $changes = [], array $final = []): QcCertificate
    {
        return $this->finish($this->start($serial, $changes), $final);
    }

    private function finish(QcInspection $inspection, array $final = []): QcCertificate
    {
        $service = app(QcInspectionService::class);
        $configuration = $final + ['cpu' => 'Intel Core i5', 'ram_mb' => 8192, 'storage_gb' => 256, 'os' => 'Windows 11'];
        $service->update($inspection, ['grade' => 'A', 'final_configuration' => $configuration], $this->owner);
        $checks = $inspection->checks()->get()->where('applicable', true)->mapWithKeys(fn ($check): array => [$check->check_key => ['result' => 'pass'] + ($check->definition['measurement'] ? ['measurement' => $check->check_key === 'temperature' ? 70 : 90] : [])])->all();
        $service->update($inspection, ['checks' => $checks], $this->owner);
        foreach (['serial', 'physical', 'display', 'system', ...(($configuration['ram_mb'] !== 8192 || $configuration['storage_gb'] !== 256 || $inspection->requested_configuration) ? ['upgrade'] : [])] as $kind) {
            app(QcEvidenceService::class)->upload($inspection, UploadedFile::fake()->image($kind.'.jpg', 800, 600), $kind, true, $this->owner);
        }

        return $service->complete($inspection->fresh(), $this->owner);
    }

    private function assign(OrderItem $item, QcCertificate $certificate): QcOrderAssignment
    {
        return app(QcOrderAssignmentService::class)->assign($item, $certificate->id, $this->owner);
    }

    private function upgrade(OrderItem $item): void
    {
        $configuration = SalesConfiguration::query()->create(['product_id' => $item->product_id, 'hardware_profile_version' => 1, 'display_name' => '16GB / 512GB', 'target_ram_mb' => 16384, 'target_storage_total_gb' => 512, 'target_storage_layout' => [], 'created_by_user_id' => $this->owner->id, 'updated_by_user_id' => $this->owner->id]);
        $recipe = UpgradeRecipe::query()->create(['sales_configuration_id' => $configuration->id, 'hardware_profile_version' => 1, 'name' => 'QC assignment fixture', 'created_by_user_id' => $this->owner->id, 'updated_by_user_id' => $this->owner->id]);
        OrderItemUpgradeSelection::query()->create(['order_item_id' => $item->id, 'sales_configuration_id' => $configuration->id, 'upgrade_recipe_id' => $recipe->id, 'hardware_profile_version' => 1, 'configuration_snapshot' => ['target_ram_mb' => 16384, 'target_storage_total_gb' => 512], 'recipe_snapshot' => [], 'recovery_snapshot' => [], 'selected_by_user_id' => $this->owner->id]);
    }

    private function grant(User $user, array $permissions): void
    {
        foreach ($permissions as $permission) {
            app(EmployeePermissionOverrideService::class)->change($user->employee, $permission->value, EmployeePermissionEffect::Allow, 'QC assignment test permission', $this->owner);
        }
    }

    private function reject(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Expected validation rejection.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    private function assertDenied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected authorization denial.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }

    private function businessSnapshot(): array
    {
        return collect(['products', 'orders', 'order_items', 'product_inventories', 'inventory_reservations', 'inventory_allocation_balances', 'inventory_allocation_events', 'inventory_allocation_reservation_lines', 'stock_movements', 'order_fulfillments', 'order_fulfillment_items'])->mapWithKeys(fn ($table): array => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }
}
