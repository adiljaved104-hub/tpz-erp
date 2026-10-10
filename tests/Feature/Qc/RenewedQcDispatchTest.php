<?php

namespace Tests\Feature\Qc;

use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\InventoryReservationStatus;
use App\Enums\OrderPermission;
use App\Enums\OrderStatus;
use App\Enums\ProductCondition;
use App\Enums\QcInspectionStatus;
use App\Enums\QcPermission;
use App\Enums\StockMovementType;
use App\Exceptions\InventoryInvariantException;
use App\Filament\Pages\Inventory\RenewedQcWorkQueue;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\WebSalesOrders\Pages\ViewWebSalesOrder;
use App\Models\EmployeePermissionOverride;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemUpgradeSelection;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\QcCertificate;
use App\Models\QcInspection;
use App\Models\QcOrderAssignment;
use App\Models\SalesConfiguration;
use App\Models\UpgradeRecipe;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\EmployeePermissionOverrideService;
use App\Services\Authorization\QcAuthorization;
use App\Services\BusinessTimezone;
use App\Services\Mobile\MobileManifest;
use App\Services\Orders\OrderService;
use App\Services\Qc\QcDocumentService;
use App\Services\Qc\QcEvidenceService;
use App\Services\Qc\QcInspectionService;
use App\Services\Qc\QcOrderAssignmentService;
use App\Services\Qc\RenewedQcDispatchService;
use App\Services\Responsibilities\ResponsibilityAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class RenewedQcDispatchTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    private array $foundation;

    private User $owner;

    private Product $product;

    private Warehouse $warehouse;

    private RenewedQcDispatchService $dispatch;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->foundation = $this->responsibilityFoundation(50);
        $this->owner = $this->foundation['owner'];
        $this->product = $this->foundation['product'];
        $this->product->update(['condition' => ProductCondition::Renewed, 'model' => 'EliteBook 840', 'processor' => 'Intel Core i5', 'ram' => '8GB', 'storage' => '256GB', 'touch_screen' => false]);
        $this->warehouse = $this->foundation['inventory']->warehouse;
        $this->dispatch = app(RenewedQcDispatchService::class);
    }

    public static function ungatedConditions(): array
    {
        return array_map(fn ($c) => [$c], [ProductCondition::New, ProductCondition::Used, ProductCondition::OpenBox, ProductCondition::Refurbished]);
    }

    #[DataProvider('ungatedConditions')]
    public function test_non_renewed_orders_keep_existing_fulfilment(ProductCondition $condition): void
    {
        $this->product->update(['condition' => $condition]);
        $order = $this->order();
        $this->assertSame('not_required', $this->dispatch->readiness($order)['status']);
        $this->assertSame(OrderStatus::Fulfilled, $this->fulfill($order)->status);
        $this->assertDatabaseCount('qc_order_assignments', 0);
    }

    #[DataProvider('ungatedConditions')]
    public function test_non_renewed_order_items_cannot_be_assigned_qc(ProductCondition $condition): void
    {
        $product = Product::factory()->create(['condition' => $condition]);
        ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $this->warehouse->id, 'available_quantity' => 10]);
        $order = $this->order(items: [new OrderItemData($product->id, 1, '500.00')]);
        $item = $order->items()->firstOrFail();
        $certificate = $this->certify('NON-RENEWED-'.$condition->value);
        $assignmentService = app(QcOrderAssignmentService::class);

        $this->assertSame(0, $assignmentService->candidates($item, $this->owner)->count());
        $this->reject(fn () => $assignmentService->assign($item, $certificate->id, $this->owner), 'order_item_id');
        $this->reject(fn () => $this->dispatch->scan($order, $item->id, $certificate->snapshot['serial'], $this->owner), 'order_item_id');
        $this->assertDatabaseCount('qc_order_assignments', 0);
    }

    public function test_missing_qc_blocks_central_fulfilment_before_any_business_mutation(): void
    {
        $order = $this->order();
        $before = $this->stockSnapshot();
        $message = $this->reject(fn () => $this->fulfill($order), 'qc');
        $this->assertStringContainsString($order->reference, $message);
        $this->assertStringContainsString('0 / 1', $message);
        $this->assertSame($before, $this->stockSnapshot());
        $this->assertSame(OrderStatus::Reserved, $order->fresh()->status);
        $this->assertDatabaseCount('order_fulfillments', 0);
    }

    public function test_one_current_scanned_unit_ships_and_retry_does_not_consume_twice(): void
    {
        $order = $this->order();
        $certificate = $this->certify();
        $this->scan($order, $certificate->snapshot['serial']);
        $this->assertSame(OrderStatus::Reserved, $order->fresh()->status);
        $key = (string) str()->uuid();
        $this->assertSame(OrderStatus::Fulfilled, $this->dispatch->ship($order, $key, $this->owner)->status);
        $before = $this->stockSnapshot();
        $this->assertSame($order->id, $this->dispatch->ship($order, $key, $this->owner)->id);
        $this->assertSame($before, $this->stockSnapshot());
        $this->assertSame(49, $this->foundation['inventory']->fresh()->available_quantity);
        $this->assertSame(0, $this->foundation['inventory']->fresh()->reserved_quantity);
        $this->assertDatabaseCount('order_fulfillments', 1);
    }

    public function test_quantity_three_requires_three_exact_units_and_scans_never_ship(): void
    {
        $order = $this->order(3);
        foreach ([1, 2, 3] as $index) {
            $certificate = $this->certify('SERIAL-'.$index);
            $code = match ($index) {
                1 => app(QcDocumentService::class)->url($certificate), 2 => $certificate->snapshot['serial'], 3 => $certificate->snapshot['reference']
            };
            $data = $this->scan($order, $code);
            $this->assertSame($index, $data['readiness']['assigned']);
            $this->assertSame(3 - $index, $data['readiness']['remaining']);
            $this->assertSame(OrderStatus::Reserved, $order->fresh()->status);
            if ($index < 3) {
                $this->reject(fn () => $this->fulfill($order), 'qc');
            }
        }
        $this->assertSame('ready', $this->dispatch->readiness($order)['status']);
        $extra = $this->certify('EXTRA-UNIT');
        $this->reject(fn () => $this->scan($order, $extra->snapshot['serial']), 'order_item_id');
        $this->assertSame(OrderStatus::Fulfilled, $this->fulfill($order)->status);
        $this->assertDatabaseCount('qc_order_assignments', 3);
    }

    public function test_verified_mobile_scan_auto_matches_first_remaining_equivalent_line_then_next_line(): void
    {
        $order = $this->order(items: [new OrderItemData($this->product->id, 1, '500.00'), new OrderItemData($this->product->id, 1, '500.00')]);
        $items = $order->items()->orderBy('line_number')->orderBy('id')->get();
        $first = $this->certify('AUTO-MATCH-1');
        $one = $this->dispatch->verifiedScan($order, $first->snapshot['serial'], $first->snapshot['serial'], null, $this->owner);
        $this->assertSame($items[0]->id, $one['matched']['order_item_id']);
        $this->assertSame('auto', $one['matched']['match_mode']);
        $this->assertSame('qc_label_and_physical_serial', $one['verification_mode']);
        $this->assertSame(1, $one['readiness']['lines'][0]['assigned']);

        $second = $this->certify('AUTO-MATCH-2');
        $two = $this->dispatch->verifiedScan($order, $second->snapshot['reference'], $second->snapshot['serial'], null, $this->owner);
        $this->assertSame($items[1]->id, $two['matched']['order_item_id']);
        $this->assertSame('auto', $two['matched']['match_mode']);
        $this->assertSame('qc_label_and_physical_serial', $two['verification_mode']);
        $this->assertSame('ready', $two['readiness']['status']);
    }

    public function test_verified_mobile_scan_rejects_wrong_physical_serial_before_assignment(): void
    {
        $order = $this->order();
        $certificate = $this->certify('LABEL-SERIAL-123');
        $message = $this->reject(fn () => $this->dispatch->verifiedScan($order, $certificate->snapshot['reference'], 'PHYSICAL-SERIAL-999', null, $this->owner), 'physical_serial');
        $this->assertSame('Physical device serial does not match the scanned QC label. DO NOT SHIP.', $message);
        $this->assertDatabaseCount('qc_order_assignments', 0);
        $this->assertSame('pending_qc', $this->dispatch->readiness($order)['status']);
    }

    public function test_verified_auto_match_rejects_product_warehouse_and_structured_configuration_mismatches(): void
    {
        $order = $this->order();
        $item = $order->items()->sole();
        $this->upgrade($item);
        $wrongProduct = Product::factory()->create(['ram' => '8GB', 'storage' => '256GB']);
        $certificates = [
            $this->certify('AUTO-WRONG-PRODUCT', ['product_id' => $wrongProduct->id]),
            $this->certify('AUTO-WRONG-WAREHOUSE', ['warehouse_id' => Warehouse::factory()->create()->id]),
            $this->certify('AUTO-WRONG-RAM', final: ['ram_mb' => 8192, 'storage_gb' => 512]),
            $this->certify('AUTO-WRONG-STORAGE', final: ['ram_mb' => 16384, 'storage_gb' => 256]),
        ];

        foreach ($certificates as $certificate) {
            $this->reject(fn () => $this->dispatch->verifiedScan($order, $certificate->snapshot['reference'], $certificate->snapshot['serial'], null, $this->owner), 'order_item_id');
        }
        $this->assertDatabaseCount('qc_order_assignments', 0);
        $this->assertSame('pending_qc', $this->dispatch->readiness($order)['status']);
    }

    public function test_verified_mobile_scan_manual_item_uses_exact_item_and_cannot_bypass_validation(): void
    {
        $order = $this->order(items: [new OrderItemData($this->product->id, 1, '500.00'), new OrderItemData($this->product->id, 1, '500.00')]);
        $items = $order->items()->orderBy('id')->get();
        $certificate = $this->certify('MANUAL-SCAN-123');
        $manual = $this->dispatch->verifiedScan($order, $certificate->snapshot['reference'], null, $items[1]->id, $this->owner);
        $this->assertSame($items[1]->id, $manual['matched']['order_item_id']);
        $this->assertSame('manual', $manual['matched']['match_mode']);
        $this->assertSame('qc_label_only', $manual['verification_mode']);

        $another = $this->certify('MANUAL-WRONG-SERIAL');
        $this->reject(fn () => $this->dispatch->verifiedScan($order, $another->snapshot['serial'], 'PHYSICAL-WRONG', $items[0]->id, $this->owner), 'physical_serial');
        $this->assertDatabaseCount('qc_order_assignments', 1);
    }

    public function test_mixed_order_requires_qc_only_for_renewed_line(): void
    {
        $new = Product::factory()->create(['condition' => ProductCondition::New]);
        $newInventory = ProductInventory::factory()->create(['product_id' => $new->id, 'warehouse_id' => $this->warehouse->id, 'available_quantity' => 10]);
        $newInventory->forceFill(['average_cost' => '100.0000'])->save();
        $order = $this->order(items: [new OrderItemData($this->product->id, 1, '500'), new OrderItemData($new->id, 2, '500')]);
        $this->assertSame(1, $this->dispatch->readiness($order)['required']);
        $this->scan($order, $this->certify()->snapshot['serial']);
        $this->assertSame(OrderStatus::Fulfilled, $this->fulfill($order)->status);
        $this->assertSame(2, DB::table('order_fulfillment_items')->where('order_fulfillment_id', $order->fresh()->fulfillment->id)->count());
    }

    public function test_pre_completed_certificate_is_never_automatically_assigned(): void
    {
        $certificate = $this->certify();
        $before = $certificate->snapshot;
        $order = $this->order();
        $this->assertSame('pending_qc', $this->dispatch->readiness($order)['status']);
        $this->assertDatabaseCount('qc_order_assignments', 0);
        $this->assertSame($before, $certificate->fresh()->snapshot);
        $this->assertDatabaseCount('qc_inspections', 1);
    }

    public function test_duplicate_scan_is_idempotent_and_audit_contains_no_token(): void
    {
        $order = $this->order();
        $certificate = $this->certify();
        $qr = app(QcDocumentService::class)->url($certificate);
        $before = $this->stockSnapshot();
        $this->scan($order, $qr);
        $this->scan($order, $qr);
        $this->assertDatabaseCount('qc_order_assignments', 1);
        $this->assertSame(1, DB::table('activity_logs')->where('event', 'qc.dispatch_scanned')->count());
        $this->assertSame($before, $this->stockSnapshot());
        $this->assertStringNotContainsString($certificate->public_token, DB::table('activity_logs')->get()->toJson());
    }

    public function test_external_and_malformed_qrs_are_rejected(): void
    {
        $order = $this->order();
        $certificate = $this->certify();
        $url = app(QcDocumentService::class)->url($certificate);
        foreach (['https://evil.example/verify/qc/'.$certificate->public_token, $url.'?x=1', $url.'#fragment', str_replace('://', '://someone@', $url), '/verify/qc/'.$certificate->public_token, 'not-a-device'] as $code) {
            $this->reject(fn () => $this->scan($order, $code), 'certificate_id');
        }
        $this->assertDatabaseCount('qc_order_assignments', 0);
    }

    public function test_wrong_product_and_warehouse_cannot_be_scanned(): void
    {
        $order = $this->order();
        $wrongProduct = Product::factory()->create(['ram' => '8GB', 'storage' => '256GB']);
        foreach ([$this->certify('WRONG-PRODUCT', ['product_id' => $wrongProduct->id]), $this->certify('WRONG-WAREHOUSE', ['warehouse_id' => Warehouse::factory()->create()->id])] as $certificate) {
            $this->reject(fn () => $this->scan($order, $certificate->snapshot['serial']), 'certificate_id');
        }
        $this->assertDatabaseCount('qc_order_assignments', 0);
    }

    public function test_device_already_on_another_order_is_not_reassigned(): void
    {
        $first = $this->order();
        $second = $this->order();
        $certificate = $this->certify();
        $this->scan($first, $certificate->snapshot['serial']);
        $this->assertStringContainsString('another Order', $this->reject(fn () => $this->scan($second, $certificate->snapshot['serial']), 'certificate_id'));
        $this->assertSame($first->id, QcOrderAssignment::query()->sole()->order_id);
    }

    public function test_pending_reqc_blocks_final_ship_after_successful_scan(): void
    {
        $order = $this->order();
        $certificate = $this->certify();
        $this->scan($order, $certificate->snapshot['serial']);
        app(QcInspectionService::class)->reopen($certificate->inspection, 'Recheck physical device', $this->owner);
        $before = $this->stockSnapshot();
        $this->assertSame('attention_required', $this->dispatch->readiness($order)['status']);
        $this->reject(fn () => $this->fulfill($order), 'qc');
        $this->assertSame($before, $this->stockSnapshot());
    }

    public function test_superseded_certificate_blocks_ship_without_rewriting_assignment(): void
    {
        $order = $this->order();
        $first = $this->certify();
        $this->scan($order, $first->snapshot['serial']);
        $second = $this->finish(app(QcInspectionService::class)->reopen($first->inspection, 'Recheck physical device', $this->owner));
        $this->reject(fn () => $this->fulfill($order), 'qc');
        $assignment = QcOrderAssignment::query()->sole();
        $this->assertSame($first->id, $assignment->qc_certificate_id);
        $this->assertSame(1, $assignment->certificate_version);
        $this->assertSame(2, $second->version);
    }

    public function test_ram_and_storage_configuration_are_checked_again_at_ship(): void
    {
        $order = $this->order();
        $item = $order->items->sole();
        $this->upgrade($item);
        $ram = $this->certify('RAM-WRONG', final: ['ram_mb' => 8192, 'storage_gb' => 512]);
        $storage = $this->certify('STORAGE-WRONG', final: ['ram_mb' => 16384, 'storage_gb' => 256]);
        foreach ([$ram, $storage] as $certificate) {
            $this->reject(fn () => $this->scan($order, $certificate->snapshot['serial']), 'certificate_id');
        }
        $match = $this->certify('CONFIG-MATCH', final: ['ram_mb' => 16384, 'storage_gb' => 512]);
        $this->scan($order, $match->snapshot['serial']);
        $this->assertSame('ready', $this->dispatch->readiness($order)['status']);
        // Simulates a later structured requirement. Never changes QC snapshots.
        DB::table('order_item_upgrade_selections')->where('order_item_id', $item->id)->update(['configuration_snapshot' => json_encode(['target_ram_mb' => 32768, 'target_storage_total_gb' => 512])]);
        $this->reject(fn () => $this->fulfill($order), 'qc');
        $this->assertDatabaseCount('order_fulfillments', 0);
    }

    public function test_certificate_version_linkage_is_checked_and_released_units_do_not_count(): void
    {
        $order = $this->order();
        $this->scan($order, $this->certify()->snapshot['serial']);
        $assignment = QcOrderAssignment::query()->sole();
        DB::table('qc_order_assignments')->where('id', $assignment->id)->update(['certificate_version' => 99]);
        $this->reject(fn () => $this->fulfill($order), 'qc');
        DB::table('qc_order_assignments')->where('id', $assignment->id)->update(['certificate_version' => 1]);
        app(QcOrderAssignmentService::class)->release($assignment->fresh(), 'Packing correction', $this->owner);
        $this->assertSame(0, $this->dispatch->readiness($order)['assigned']);
        $this->reject(fn () => $this->fulfill($order), 'qc');
        $this->assertDatabaseCount('qc_order_assignments', 1);
    }

    public function test_sales_button_and_web_sales_cannot_bypass_backend_gate(): void
    {
        $order = $this->order();
        Livewire::actingAs($this->owner)->test(ViewOrder::class, ['record' => $order->id])->assertActionDisabled('saveAsShipped')->assertSee('Pending Qc');
        $order->update(['web_sales_channel' => 'other', 'delivery_type' => 'shop_pickup', 'customer_name' => 'Synthetic', 'customer_phone' => '0500000000']);
        Livewire::actingAs($this->owner)->test(ViewWebSalesOrder::class, ['record' => $order->id])->assertActionDisabled('ship');
        $this->reject(fn () => $this->fulfill($order), 'qc');
    }

    public function test_non_renewed_web_sale_ships_without_qc(): void
    {
        $this->product->update(['condition' => ProductCondition::New]);
        $order = $this->order();
        $order->update(['web_sales_channel' => 'other', 'delivery_type' => 'shop_pickup', 'customer_name' => 'Synthetic', 'customer_phone' => '0500000000']);
        Livewire::actingAs($this->owner)->test(ViewWebSalesOrder::class, ['record' => $order->id])->assertActionEnabled('ship');
        $this->assertSame(OrderStatus::Fulfilled, $this->fulfill($order)->status);
    }

    public function test_direct_save_as_shipped_cannot_bypass_renewed_gate(): void
    {
        $before = $this->stockSnapshot();
        $this->reject(fn () => app(OrderService::class)->saveAsShipped($this->orderData(), $this->owner), 'items');
        $this->assertSame($before, $this->stockSnapshot());
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_staff_can_scan_another_technicians_certificate_without_internal_viewall(): void
    {
        $staff = $this->dispatchStaff();
        $order = $this->order();
        $certificate = $this->certify();
        $this->assertFalse(app(QcAuthorization::class)->allows($staff, QcPermission::ViewAll));
        $this->assertFalse(app(QcAuthorization::class)->allows($staff, QcPermission::View, $certificate->inspection));
        $data = $this->dispatch->scan($order, $order->items->sole()->id, $certificate->snapshot['serial'], $staff);
        $this->assertSame('ready', $data['readiness']['status']);
        foreach (['internal_remarks', 'public_token', 'private_path', 'evidence', 'cost', 'profit'] as $key) {
            $this->assertArrayNotHasKey($key, $data['device']);
        }
        $this->assertStringNotContainsString($certificate->public_token, json_encode($data));
    }

    public function test_dispatch_and_assignment_permissions_are_not_implicit(): void
    {
        $staff = $this->dispatchStaff();
        $order = $this->order();
        $certificate = $this->certify();
        foreach ([QcPermission::ScanDispatch, QcPermission::AssignOrderDevice, QcPermission::ViewOrderAssignments, QcPermission::ViewDispatchQueue] as $permission) {
            $this->override($staff, $permission, EmployeePermissionEffect::Deny);
            $this->denied(fn () => $this->dispatch->scan($order, $order->items->sole()->id, $certificate->snapshot['serial'], $staff));
            $this->override($staff, $permission, EmployeePermissionEffect::Allow);
        }
        $this->dispatch->scan($order, $order->items->sole()->id, $certificate->snapshot['serial'], $staff);
        $this->override($staff, QcPermission::ShipDispatch, EmployeePermissionEffect::Deny);
        $this->denied(fn () => app(OrderService::class)->fulfill($order, (string) str()->uuid(), $staff));
        $this->override($staff, QcPermission::ShipDispatch, EmployeePermissionEffect::Allow);
        $this->override($staff, OrderPermission::Fulfill, EmployeePermissionEffect::Deny);
        $this->denied(fn () => $this->dispatch->ship($order, (string) str()->uuid(), $staff));
        $this->assertDatabaseCount('order_fulfillments', 0);
    }

    public function test_queue_and_scan_fail_closed_for_unrelated_employee(): void
    {
        $staff = $this->dispatchStaff(scoped: false);
        $order = $this->order();
        $this->assertSame(0, $this->dispatch->queueQuery($staff)->count());
        $this->denied(fn () => $this->dispatch->detail($order, $staff));
        $this->denied(fn () => $this->dispatch->scan($order, $order->items->sole()->id, $this->certify()->snapshot['serial'], $staff));
    }

    public function test_wrong_selected_order_item_is_rejected(): void
    {
        $order = $this->order();
        $other = $this->order();
        $this->reject(fn () => $this->dispatch->scan($order, $other->items->sole()->id, $this->certify()->snapshot['serial'], $this->owner), 'order_item_id');
        $this->assertDatabaseCount('qc_order_assignments', 0);
    }

    public function test_queue_renders_operational_data_without_financials_and_manual_scan_works(): void
    {
        $order = $this->order();
        $staff = $this->dispatchStaff();
        $certificate = $this->certify();
        Livewire::actingAs($staff)->test(RenewedQcWorkQueue::class)->assertCanSeeTableRecords([$order])
            ->assertDontSee('Average Cost')->assertDontSee('Profit')->callTableAction('scanQc', $order, ['order_item_id' => $order->items->sole()->id, 'code' => $certificate->snapshot['serial']])->assertHasNoTableActionErrors();
        $this->assertSame('ready', $this->dispatch->readiness($order)['status']);
        $this->assertSame(OrderStatus::Reserved, $order->fresh()->status);
    }

    public function test_qc_only_manifest_is_explicit_and_does_not_remove_broader_owner_admin_access(): void
    {
        $staff = $this->dispatchStaff();
        $manifest = app(MobileManifest::class);
        $keys = fn (User $user) => array_column($manifest->forUser($user)['modules'], 'key');
        $normalManifest = $manifest->forUser($staff);
        $this->assertContains('sales', $keys($staff));
        $this->override($staff, QcPermission::FocusedWorkspace, EmployeePermissionEffect::Allow);
        $focusedManifest = $manifest->forUser($staff);
        $this->assertSame(['qc'], $keys($staff));
        $this->assertSame('qc_focused', $focusedManifest['workspace_mode']);
        $this->assertSame('qc', $focusedManifest['landing_module']);
        $qcModule = collect($focusedManifest['modules'])->firstWhere('key', 'qc');
        $this->assertTrue($qcModule['capabilities']['inspections']);
        $this->assertTrue($qcModule['capabilities']['dispatch']);
        $this->assertTrue($qcModule['capabilities']['scan']);
        $this->assertFalse($qcModule['capabilities']['inspection_update']);
        $this->assertFalse($qcModule['capabilities']['inspection_complete']);
        $this->assertFalse($qcModule['capabilities']['inspection_evidence']);
        $this->assertSame('/workspace/qc/inspections/{inspection}', $qcModule['capabilities']['endpoints']['inspection_detail']);
        $this->assertSame('/workspace/qc/inspections/{inspection}/begin', $qcModule['capabilities']['endpoints']['inspection_begin']);
        $this->assertSame('/workspace/qc/inspections/{inspection}/evidence', $qcModule['capabilities']['endpoints']['inspection_evidence']);
        $this->override($staff, QcPermission::Update, EmployeePermissionEffect::Allow);
        $this->override($staff, QcPermission::Complete, EmployeePermissionEffect::Allow);
        $qcModule = collect($manifest->forUser($staff)['modules'])->firstWhere('key', 'qc');
        $this->assertTrue($qcModule['capabilities']['inspection_update']);
        $this->assertTrue($qcModule['capabilities']['inspection_complete']);
        $this->assertTrue($qcModule['capabilities']['inspection_evidence']);
        $this->override($staff, QcPermission::ViewOrderAssignments, EmployeePermissionEffect::Deny);
        $qcModule = collect($manifest->forUser($staff)['modules'])->firstWhere('key', 'qc');
        $this->assertFalse($qcModule['capabilities']['scan']);
        $this->assertSame('default', $normalManifest['workspace_mode']);
        $this->assertNull($normalManifest['landing_module']);

        $manager = $this->responsibilityUser(EmployeeRole::Manager);
        foreach ([QcPermission::View, QcPermission::ViewDispatchQueue, QcPermission::FocusedWorkspace, OrderPermission::View] as $permission) {
            EmployeePermissionOverride::query()->create([
                'employee_id' => $manager->employee->id,
                'permission_key' => $permission->value,
                'effect' => EmployeePermissionEffect::Allow,
                'granted_by_user_id' => $this->owner->id,
                'reason' => 'QC mobile presentation test',
            ]);
        }
        $this->assertSame(['qc'], $keys($manager));
        $this->assertSame('qc_focused', $manifest->forUser($manager)['workspace_mode']);

        foreach ([$this->owner, $this->responsibilityUser(EmployeeRole::Admin)] as $user) {
            $this->assertContains('qc', $keys($user));
            $this->assertContains('sales', $keys($user));
            $this->assertContains('purchases', $keys($user));
            $this->assertSame('default', $manifest->forUser($user)['workspace_mode']);
            $this->assertNull($manifest->forUser($user)['landing_module']);
        }
    }

    public function test_inspection_only_focused_technician_receives_qc_without_dispatch_access(): void
    {
        $technician = $this->responsibilityUser(EmployeeRole::Staff);
        foreach ([QcPermission::View, QcPermission::FocusedWorkspace] as $permission) {
            $this->override($technician, $permission, EmployeePermissionEffect::Allow);
        }

        $manifest = app(MobileManifest::class)->forUser($technician);
        $this->assertSame(['qc'], array_column($manifest['modules'], 'key'));
        $this->assertSame('qc_focused', $manifest['workspace_mode']);
        $this->assertSame('qc', $manifest['landing_module']);
        $qc = $manifest['modules'][0];
        $this->assertSame(['inspections' => true, 'dispatch' => false, 'scan' => false, 'ship' => false], array_intersect_key(
            $qc['capabilities'], array_flip(['inspections', 'dispatch', 'scan', 'ship']),
        ));

        $this->mobile($technician);
        $home = $this->getJson('/api/mobile/v1/workspace/qc');
        $home->assertOk();
        $home
            ->assertJsonPath('data.reserved_orders', 0)
            ->assertJsonPath('data.pending', 0)
            ->assertJsonPath('data.my_in_progress', 0)
            ->assertJsonPath('data.completed_today', 0)
            ->assertJsonPath('data.capabilities.access.inspections', true)
            ->assertJsonPath('data.capabilities.access.dispatch', false)
            ->assertJsonPath('data.capabilities.dispatch', '/workspace/qc/dispatch')
            ->assertJsonPath('data.capabilities.individual_scan', false)
            ->assertJsonPath('data.capabilities.explicit_ship', false);
        $this->getJson('/api/mobile/v1/workspace/qc/inspections?scope=pending')->assertOk();
        $this->getJson('/api/mobile/v1/workspace/qc/dispatch')->assertForbidden();
    }

    public function test_mobile_qc_inspection_scopes_are_visible_paginated_and_safe(): void
    {
        $actor = $this->owner;
        $other = $this->responsibilityUser(EmployeeRole::Staff);

        $start = fn (User $actor, string $serial) => app(QcInspectionService::class)->start([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'serial' => $serial,
            'features' => [],
            'idempotency_key' => (string) str()->uuid(),
        ], $actor);

        $ownPending = $start($actor, 'MOBILE-PENDING-OWN');
        $otherPending = $start($actor, 'MOBILE-PENDING-OTHER');
        $ownInProgress = $start($actor, 'MOBILE-IN-PROGRESS');
        $ownInProgress->forceFill(['status' => QcInspectionStatus::InProgress])->save();
        $ownRework = $start($actor, 'MOBILE-REWORK');
        $ownRework->forceFill(['status' => QcInspectionStatus::Rework])->save();
        $otherPending->forceFill(['technician_user_id' => $other->id])->save();
        $otherInProgress = $start($actor, 'MOBILE-OTHER-IN-PROGRESS');
        $otherInProgress->forceFill(['status' => QcInspectionStatus::InProgress])->save();
        $otherInProgress->forceFill(['technician_user_id' => $other->id])->save();

        $this->mobile($actor);
        $prefix = '/api/mobile/v1/workspace/qc/inspections';

        $pending = $this->getJson($prefix.'?scope=pending&per_page=1')->assertOk()
            ->assertJsonPath('scope', 'pending')
            ->assertJsonPath('per_page', 1)
            ->assertJsonPath('total', 2);
        $this->assertContains($pending->json('data.0.inspection_id'), [$ownPending->id, $otherPending->id]);
        $this->assertSame('pending', $pending->json('data.0.status'));

        $mine = $this->getJson($prefix.'?scope=mine')->assertOk()
            ->assertJsonPath('total', 2);
        $mineIds = collect($mine->json('data'))->pluck('inspection_id')->all();
        $this->assertEqualsCanonicalizing([$ownInProgress->id, $ownRework->id], $mineIds);
        $this->assertNotContains($otherInProgress->id, $mineIds);
        $this->assertSame('Asia/Dubai', $mine->json('timezone'));
        $this->assertArrayNotHasKey('internal_remarks', $mine->json('data.0'));
        $this->assertArrayNotHasKey('private_path', $mine->json('data.0'));
        $this->assertArrayNotHasKey('evidence', $mine->json('data.0'));

        $this->getJson($prefix.'?scope=unknown')->assertUnprocessable();
    }

    public function test_mobile_qc_inspection_api_returns_403_without_qc_view_permission(): void
    {
        $unauthorized = $this->responsibilityUser(EmployeeRole::Staff);
        $this->assertFalse(app(QcAuthorization::class)->allows($unauthorized, QcPermission::View));
        $this->mobile($unauthorized);
        $this->getJson('/api/mobile/v1/workspace/qc/inspections?scope=pending')->assertForbidden();
    }

    public function test_mobile_qc_inspection_visibility_uses_existing_technician_scope(): void
    {
        $staff = $this->dispatchStaff();
        $other = $this->responsibilityUser(EmployeeRole::Staff);
        $own = app(QcInspectionService::class)->start([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'serial' => 'VISIBLE-PENDING-OWN',
            'features' => [],
            'idempotency_key' => (string) str()->uuid(),
        ], $this->owner);
        $foreign = app(QcInspectionService::class)->start([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'serial' => 'VISIBLE-PENDING-FOREIGN',
            'features' => [],
            'idempotency_key' => (string) str()->uuid(),
        ], $this->owner);
        DB::table('qc_inspections')->where('id', $own->id)->update(['technician_user_id' => $staff->id]);
        DB::table('qc_inspections')->where('id', $foreign->id)->update(['technician_user_id' => $other->id]);

        $this->mobile($staff);
        $response = $this->getJson('/api/mobile/v1/workspace/qc/inspections?scope=pending')->assertOk()
            ->assertJsonPath('total', 1);
        $this->assertSame($own->id, $response->json('data.0.inspection_id'));
    }

    public function test_mobile_qc_completed_today_uses_business_timezone_and_home_counters_match_lists(): void
    {
        config(['business.timezone' => 'Asia/Karachi']);
        $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00', 'Asia/Karachi'));

        $staff = $this->dispatchStaff();
        $pending = app(QcInspectionService::class)->start([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'serial' => 'COUNTER-PENDING',
            'features' => [],
            'idempotency_key' => (string) str()->uuid(),
        ], $this->owner);
        DB::table('qc_inspections')->where('id', $pending->id)->update(['technician_user_id' => $staff->id]);
        $inProgress = app(QcInspectionService::class)->start([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'serial' => 'COUNTER-IN-PROGRESS',
            'features' => [],
            'idempotency_key' => (string) str()->uuid(),
        ], $this->owner);
        DB::table('qc_inspections')->where('id', $inProgress->id)->update(['technician_user_id' => $staff->id]);
        $inProgress->forceFill(['status' => QcInspectionStatus::InProgress])->save();
        $rework = app(QcInspectionService::class)->start([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'serial' => 'COUNTER-REWORK',
            'features' => [],
            'idempotency_key' => (string) str()->uuid(),
        ], $this->owner);
        DB::table('qc_inspections')->where('id', $rework->id)->update(['technician_user_id' => $staff->id]);
        $rework->forceFill(['status' => QcInspectionStatus::Rework])->save();

        $todayCertificate = $this->certify('COUNTER-COMPLETED-TODAY');
        $previousCertificate = $this->certify('COUNTER-COMPLETED-PREVIOUS-DAY');
        $startUtc = CarbonImmutable::parse('2026-10-07 00:00:00', 'Asia/Karachi')->utc();
        DB::table('qc_inspections')->where('id', $todayCertificate->inspection_id)->update([
            'technician_user_id' => $staff->id,
            'completed_at' => $startUtc->toDateTimeString(),
        ]);
        DB::table('qc_inspections')->where('id', $previousCertificate->inspection_id)->update([
            'technician_user_id' => $staff->id,
            'completed_at' => $startUtc->subSecond()->toDateTimeString(),
        ]);

        $this->mobile($staff);
        $prefix = '/api/mobile/v1/workspace/qc';
        $home = $this->getJson($prefix)->assertOk();
        $this->assertArrayHasKey('reserved_orders', $home->json('data'));
        $this->assertArrayHasKey('timezone', $home->json('data'));
        $this->assertSame('/workspace/qc/pending', $home->json('data.capabilities.queue'));
        $this->assertSame('/workspace/qc/dispatch', $home->json('data.capabilities.dispatch'));
        $this->assertArrayHasKey('individual_scan', $home->json('data.capabilities'));
        $this->assertArrayHasKey('explicit_ship', $home->json('data.capabilities'));
        $this->assertArrayHasKey('bulk_ship_limit', $home->json('data.capabilities'));
        $this->getJson($prefix.'/inspections?scope=completed_today')->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.inspection_id', $todayCertificate->inspection_id)
            ->assertJsonPath('timezone', 'Asia/Karachi');
        $this->getJson($prefix.'/inspections?scope=mine')->assertOk()->assertJsonPath('total', 2);
        $this->getJson($prefix.'/inspections?scope=pending')->assertOk()->assertJsonPath('total', 1);
        $this->getJson($prefix)->assertOk()
            ->assertJsonPath('data.pending', 1)
            ->assertJsonPath('data.my_in_progress', 2)
            ->assertJsonPath('data.completed_today', 1)
            ->assertJsonPath('data.timezone', 'Asia/Karachi');
    }

    public function test_mobile_qc_endpoints_require_real_eligible_authentication(): void
    {
        foreach (['', '/pending', '/dispatch', '/orders/1'] as $path) {
            $this->getJson('/api/mobile/v1/workspace/qc'.$path)->assertUnauthorized();
        }
        $this->postJson('/api/mobile/v1/workspace/qc/orders/1/scan', [])->assertUnauthorized();
        $this->postJson('/api/mobile/v1/workspace/qc/orders/1/ship', [])->assertUnauthorized();
        $this->postJson('/api/mobile/v1/workspace/qc/bulk-ship', [])->assertUnauthorized();
    }

    public function test_mobile_individual_scan_and_ship_with_whitelisted_payload_and_safe_retry(): void
    {
        $order = $this->order();
        $certificate = $this->certify();
        $this->mobile($this->owner);
        $prefix = '/api/mobile/v1/workspace/qc';
        $this->getJson($prefix)->assertOk()->assertJsonPath('data.reserved_orders', 1);
        $this->getJson($prefix.'/pending')->assertOk()->assertJsonPath('data.0.reference', $order->reference);
        $this->postJson($prefix.'/orders/'.$order->id.'/scan', ['order_item_id' => $order->items->sole()->id, 'code' => app(QcDocumentService::class)->url($certificate)])
            ->assertOk()->assertJsonPath('data.readiness.status', 'ready')->assertJsonPath('data.device.serial', $certificate->snapshot['serial']);
        $response = $this->getJson($prefix.'/orders/'.$order->id)->assertOk();
        foreach (['public_token', 'private_path', 'internal_remarks', 'selling_price', 'cost_price', 'average_cost', 'profit'] as $key) {
            $this->assertStringNotContainsString('"'.$key.'"', $response->getContent());
        }
        $this->assertStringNotContainsString($certificate->public_token, $response->getContent());
        $key = (string) str()->uuid();
        foreach ([1, 2] as $attempt) {
            $this->postJson($prefix.'/orders/'.$order->id.'/ship', ['idempotency_key' => $key])->assertOk()->assertJsonPath('data.status', 'shipped');
        }
        $this->assertDatabaseCount('order_fulfillments', 1);
    }

    public function test_mobile_verified_scan_allows_label_only_auto_and_manual_assignment_without_shipping(): void
    {
        $autoOrder = $this->order();
        $autoCertificate = $this->certify('MOBILE-LABEL-ONLY-AUTO');
        $this->mobile($this->owner);
        $this->postJson('/api/mobile/v1/workspace/qc/orders/'.$autoOrder->id.'/verified-scan', [
            'code' => app(QcDocumentService::class)->url($autoCertificate),
        ])->assertOk()
            ->assertJsonPath('data.verification_mode', 'qc_label_only')
            ->assertJsonPath('data.matched.match_mode', 'auto')
            ->assertJsonPath('data.device.serial', $autoCertificate->snapshot['serial'])
            ->assertJsonPath('data.readiness.status', 'ready');
        $this->assertSame(OrderStatus::Reserved, $autoOrder->fresh()->status);
        $this->assertDatabaseCount('order_fulfillments', 0);
        $this->assertSame('qc_label_only', DB::table('activity_logs')->where('event', 'qc.dispatch_scanned')->latest('id')->value('properties->verification_mode'));

        $manualOrder = $this->order();
        $manualItem = $manualOrder->items()->sole();
        $manualCertificate = $this->certify('MOBILE-LABEL-ONLY-MANUAL');
        $this->postJson('/api/mobile/v1/workspace/qc/orders/'.$manualOrder->id.'/verified-scan', [
            'code' => $manualCertificate->snapshot['reference'],
            'physical_serial' => null,
            'order_item_id' => $manualItem->id,
        ])->assertOk()
            ->assertJsonPath('data.verification_mode', 'qc_label_only')
            ->assertJsonPath('data.matched.match_mode', 'manual')
            ->assertJsonPath('data.device.serial', $manualCertificate->snapshot['serial']);
        $this->assertSame(OrderStatus::Reserved, $manualOrder->fresh()->status);
        $this->assertDatabaseCount('order_fulfillments', 0);
    }

    public function test_mobile_unready_ship_and_tampered_scan_return_clear_validation(): void
    {
        $order = $this->order();
        $this->mobile($this->owner);
        $prefix = '/api/mobile/v1/workspace/qc/orders/'.$order->id;
        $this->postJson($prefix.'/ship', ['idempotency_key' => (string) str()->uuid()])->assertUnprocessable()->assertJsonValidationErrors('qc');
        $this->postJson($prefix.'/scan', ['order_item_id' => 99999, 'code' => 'UNKNOWN'])->assertUnprocessable()->assertJsonValidationErrors('order_item_id');
        $this->assertDatabaseCount('order_fulfillments', 0);
    }

    public function test_order_viewers_without_assignment_detail_permission_get_only_readiness_summary(): void
    {
        $order = $this->order();
        $certificate = $this->certify();
        $this->scan($order, $certificate->snapshot['serial']);
        app(QcInspectionService::class)->reopen($certificate->inspection, 'Recheck physical device', $this->owner);
        $staff = $this->dispatchStaff();
        $this->override($staff, QcPermission::ViewOrderAssignments, EmployeePermissionEffect::Deny);

        $detail = $this->dispatch->detail($order, $staff);

        $this->assertSame('attention_required', $detail['readiness']['status']);
        $this->assertSame([], $detail['readiness']['lines'][0]['errors']);
        $this->assertSame([], $detail['devices']);
        $this->assertStringNotContainsString($certificate->snapshot['reference'], json_encode($detail, JSON_THROW_ON_ERROR));
    }

    public function test_bulk_dispatch_is_per_order_and_retries_are_idempotent(): void
    {
        $ready = $this->order();
        $pending = $this->order();
        $this->scan($ready, $this->certify()->snapshot['serial']);
        $rows = array_map(fn ($o) => ['order_id' => $o->id, 'idempotency_key' => (string) str()->uuid()], [$ready, $pending]);
        $this->mobile($this->owner);
        foreach ([1, 2] as $attempt) {
            $this->postJson('/api/mobile/v1/workspace/qc/bulk-ship', ['orders' => $rows])->assertOk()->assertJsonPath('data.0.status', 'shipped')->assertJsonPath('data.1.status', 'not_ready');
        }
        $this->assertSame(OrderStatus::Fulfilled, $ready->fresh()->status);
        $this->assertSame(OrderStatus::Reserved, $pending->fresh()->status);
        $this->assertDatabaseCount('order_fulfillments', 1);
    }

    public function test_bulk_hides_unauthorized_reference_and_has_a_bounded_unique_request(): void
    {
        $order = $this->order();
        $staff = $this->dispatchStaff(scoped: false);
        $result = $this->dispatch->bulkShip([['order_id' => $order->id, 'idempotency_key' => (string) str()->uuid()]], $staff);
        $this->assertNull($result[0]['reference']);
        $this->assertSame('failed', $result[0]['status']);
        $rows = array_fill(0, 51, ['order_id' => $order->id, 'idempotency_key' => (string) str()->uuid()]);
        $this->reject(fn () => $this->dispatch->bulkShip($rows, $this->owner), 'orders');
    }

    public function test_cross_order_shipment_idempotency_key_cannot_return_another_order(): void
    {
        $order = $this->order();
        $other = $this->order();
        $this->scan($order, $this->certify()->snapshot['serial']);
        $key = (string) str()->uuid();
        $this->fulfill($order, $key);
        $this->reject(fn () => $this->fulfill($other, $key), 'idempotency_key');
        $this->assertSame(OrderStatus::Reserved, $other->fresh()->status);
    }

    public function test_failed_normal_stock_fulfilment_keeps_assignment_and_rolls_back_posting(): void
    {
        $order = $this->order();
        $this->scan($order, $this->certify()->snapshot['serial']);
        // Deliberately broken disposable reservation fixture, not active business data.
        DB::table('inventory_reservations')->where('id', $order->items->sole()->reservation->id)->update(['status' => 'released']);
        $before = $this->stockSnapshot();
        $failure = null;
        try {
            $this->fulfill($order);
        } catch (\Throwable $exception) {
            $failure = $exception;
        }
        $this->assertInstanceOf(InventoryInvariantException::class, $failure);
        $this->assertSame($before, $this->stockSnapshot());
        $this->assertDatabaseCount('qc_order_assignments', 1);
        $this->assertDatabaseCount('order_fulfillments', 0);
    }

    public function test_business_timezone_changes_presentation_not_immutable_instants(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 7)->setTime(22, 30));
        $order = $this->order();
        $certificate = $this->certify();
        $snapshot = $certificate->snapshot;
        $data = $this->scan($order, $certificate->snapshot['serial']);
        $clock = app(BusinessTimezone::class);
        $this->assertSame('2026-10-08T02:30:00+04:00', $data['device']['certified_at']);
        $this->assertSame('2026-10-08T02:30:00+04:00', $data['device']['created_at']);
        $this->assertSame('2026-10-08T02:30:00+04:00', $data['device']['completed_at']);
        $this->assertSame('2026-10-08T02:30:00+04:00', $data['device']['assigned_at']);
        $this->assertSame('08 Oct 2026 02:30 +04', $clock->format($certificate->certified_at));
        config(['business.timezone' => 'Asia/Karachi']);
        $this->assertSame('2026-10-08T03:30:00+05:00', $clock->iso($certificate->certified_at));
        $this->assertSame($snapshot, $certificate->fresh()->snapshot);
        $this->assertSame('UTC', config('app.timezone'));
        $assignment = QcOrderAssignment::query()->sole();
        app(QcOrderAssignmentService::class)->release($assignment, 'Packing correction', $this->owner);
        $this->assertSame('2026-10-08T03:30:00+05:00', $this->dispatch->certificateData($certificate, $assignment->fresh())['released_at']);
    }

    public function test_competing_scans_cannot_assign_same_device_to_two_orders_or_overfill_one_item(): void
    {
        $first = $this->order();
        $second = $this->order();
        $one = $this->certify('RACE-FIRST');
        $two = $this->certify('RACE-SECOND');
        foreach ([[['scan', $first->id, $one->snapshot['serial']], ['scan', $second->id, $one->snapshot['serial']]], [['scan', $first->id, $one->snapshot['serial']], ['scan', $first->id, $two->snapshot['serial']]]] as $requests) {
            $this->race($requests, function (array $outcomes, \PDO $copy): void {
                sort($outcomes);
                $this->assertSame(['assigned', 'rejected'], $outcomes);
                $this->assertSame(1, (int) $copy->query('SELECT COUNT(*) FROM qc_order_assignments WHERE released_at IS NULL')->fetchColumn());
                $this->assertSame(0, (int) $copy->query('SELECT COUNT(*) FROM order_fulfillments')->fetchColumn());
            });
        }
        $this->assertDatabaseCount('qc_order_assignments', 0);
    }

    public function test_competing_verified_auto_scans_cannot_duplicate_a_device_or_overfill_a_line(): void
    {
        $first = $this->order();
        $second = $this->order();
        $one = $this->certify('VERIFIED-RACE-FIRST');
        $two = $this->certify('VERIFIED-RACE-SECOND');
        $sameDevice = json_encode(['code' => $one->snapshot['serial'], 'physical_serial' => $one->snapshot['serial']], JSON_THROW_ON_ERROR);
        $otherDevice = json_encode(['code' => $two->snapshot['serial'], 'physical_serial' => $two->snapshot['serial']], JSON_THROW_ON_ERROR);

        foreach ([
            [['verified-scan', $first->id, $sameDevice], ['verified-scan', $second->id, $sameDevice]],
            [['verified-scan', $first->id, $sameDevice], ['verified-scan', $first->id, $otherDevice]],
        ] as $requests) {
            $this->race($requests, function (array $outcomes, \PDO $copy): void {
                sort($outcomes);
                $this->assertSame(['assigned', 'rejected'], $outcomes);
                $this->assertSame(1, (int) $copy->query('SELECT COUNT(*) FROM qc_order_assignments WHERE released_at IS NULL')->fetchColumn());
                $this->assertSame(0, (int) $copy->query('SELECT COUNT(*) FROM order_fulfillments')->fetchColumn());
            });
        }
        $this->assertDatabaseCount('qc_order_assignments', 0);
    }

    public function test_simultaneous_scan_and_ship_cannot_create_partial_fulfilment(): void
    {
        $order = $this->order();
        $certificate = $this->certify();
        $shipmentKey = (string) str()->uuid();
        $this->race([['scan', $order->id, $certificate->snapshot['serial']], ['ship', $order->id, $shipmentKey]], function (array $outcomes, \PDO $copy) use ($order): void {
            $this->assertSame('assigned', $outcomes[0]);
            $this->assertContains($outcomes[1], ['shipped', 'rejected']);
            $shipped = $outcomes[1] === 'shipped';
            $orderState = $copy->prepare('SELECT status FROM orders WHERE id = ?');
            $orderState->execute([$order->id]);
            $this->assertSame($shipped ? OrderStatus::Fulfilled->value : OrderStatus::Reserved->value, $orderState->fetchColumn());

            $fulfillments = $copy->prepare('SELECT id, movement_group FROM order_fulfillments WHERE order_id = ?');
            $fulfillments->execute([$order->id]);
            $rows = $fulfillments->fetchAll(\PDO::FETCH_ASSOC);
            $this->assertCount($shipped ? 1 : 0, $rows);

            $items = $copy->prepare('SELECT COUNT(*) FROM order_fulfillment_items i JOIN order_fulfillments f ON f.id = i.order_fulfillment_id WHERE f.order_id = ?');
            $items->execute([$order->id]);
            $this->assertSame($shipped ? 1 : 0, (int) $items->fetchColumn());

            $movements = $copy->prepare('SELECT COUNT(*) FROM stock_movements m JOIN order_fulfillments f ON f.movement_group = m.movement_group WHERE f.order_id = ? AND m.movement_type = ?');
            $movements->execute([$order->id, StockMovementType::OrderFulfillment->value]);
            $this->assertSame($shipped ? 1 : 0, (int) $movements->fetchColumn());

            $reservations = $copy->prepare('SELECT status, released_at, fulfilled_at FROM inventory_reservations r JOIN order_items i ON i.id = r.order_item_id WHERE i.order_id = ?');
            $reservations->execute([$order->id]);
            $reservationRows = $reservations->fetchAll(\PDO::FETCH_ASSOC);
            $this->assertCount(1, $reservationRows);
            if ($shipped) {
                $this->assertSame(InventoryReservationStatus::Fulfilled->value, $reservationRows[0]['status']);
                $this->assertNotNull($reservationRows[0]['fulfilled_at']);
            } else {
                $this->assertSame(InventoryReservationStatus::Active->value, $reservationRows[0]['status']);
                $this->assertNull($reservationRows[0]['released_at']);
                $this->assertNull($reservationRows[0]['fulfilled_at']);
            }
        });
    }

    public function test_simultaneous_shipment_retries_create_one_fulfilment(): void
    {
        $order = $this->order();
        $this->scan($order, $this->certify()->snapshot['serial']);
        $key = (string) str()->uuid();
        $this->race([['ship', $order->id, $key], ['ship', $order->id, $key]], function (array $outcomes, \PDO $copy): void {
            $this->assertSame(['shipped', 'shipped'], $outcomes);
            $this->assertSame(1, (int) $copy->query('SELECT COUNT(*) FROM order_fulfillments')->fetchColumn());
            $this->assertSame(1, (int) $copy->query('SELECT COUNT(*) FROM order_fulfillment_items')->fetchColumn());
        });
    }

    public function test_reinspection_and_shipment_share_the_device_mutex(): void
    {
        $order = $this->order();
        $certificate = $this->certify();
        $this->scan($order, $certificate->snapshot['serial']);
        $this->race([['reopen', $order->id, (string) $certificate->inspection_id], ['ship', $order->id, (string) str()->uuid()]], function (array $outcomes, \PDO $copy): void {
            $this->assertSame('reopened', $outcomes[0]);
            $this->assertContains($outcomes[1], ['shipped', 'rejected']);
            $this->assertSame($outcomes[1] === 'shipped' ? 1 : 0, (int) $copy->query('SELECT COUNT(*) FROM order_fulfillments')->fetchColumn());
            $this->assertSame(1, (int) $copy->query('SELECT COUNT(*) FROM qc_order_assignments WHERE released_at IS NULL')->fetchColumn());
        });
    }

    private function race(array $requests, callable $assert): void
    {
        $path = sys_get_temp_dir().'/tpz-qc-dispatch-race-'.str()->uuid().'.sqlite';
        touch($path);
        $workers = [];
        $copy = null;
        $statement = null;
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
            foreach (DB::select("SELECT sql FROM sqlite_master WHERE type='index' AND sql IS NOT NULL") as $index) {
                $copy->exec($index->sql);
            }
            $copy->exec('PRAGMA journal_mode=WAL');
            $statement = $copy = null;
            foreach ($requests as $slot => [$operation, $orderId, $value]) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/RenewedQcDispatchWorker.php'), $path, $operation, (string) $orderId, $value, (string) $this->owner->id, (string) $slot], base_path(), timeout: 45);
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
                $this->assertFileExists($path.'.ready.'.$slot, $worker->getErrorOutput());
            }
            touch($path.'.go');
            $outcomes = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $outcomes[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR)['outcome'];
            }
            $copy = new \PDO('sqlite:'.$path);
            $assert($outcomes, $copy);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            $statement = $copy = null;
            $workers = [];
            unset($worker);
            gc_collect_cycles();
            if (is_file($path)) {
                try {
                    $cleanupConnection = new \PDO('sqlite:'.$path);
                    $cleanupConnection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                    $cleanupConnection->exec('PRAGMA busy_timeout=5000');
                    $checkpoint = $cleanupConnection->query('PRAGMA wal_checkpoint(TRUNCATE)');
                    $checkpoint->closeCursor();
                    $checkpoint = null;
                    $cleanupConnection->exec('PRAGMA journal_mode=DELETE');
                    $cleanupConnection = null;
                } catch (\Throwable) {
                    $cleanupConnection = null;
                }
                gc_collect_cycles();
            }
            // Remove SQLite's sidecars and barrier markers before the database so
            // Windows can release the WAL mapping before the main file is unlinked.
            $temporaryFiles = [$path.'.go', $path.'.ready.0', $path.'.ready.1', $path.'-journal', $path.'-wal', $path.'-shm', $path];
            foreach ($temporaryFiles as $temporary) {
                for ($attempt = 0; is_file($temporary) && $attempt < 80; $attempt++) {
                    @unlink($temporary);
                    if (is_file($temporary)) {
                        usleep(50_000);
                    }
                }
            }
            foreach ($temporaryFiles as $temporary) {
                if (is_file($temporary)) {
                    throw new \RuntimeException('A disposable QC dispatch race fixture could not be removed.');
                }
            }
        }
    }

    private function orderData(int $quantity = 1, ?array $items = null): SaveAndReserveOrderData
    {
        return new SaveAndReserveOrderData($this->warehouse->id, null, null, now()->toDateString(), $this->owner->employee->id, null, $items ?? [new OrderItemData($this->product->id, $quantity, '500.00')], (string) str()->uuid());
    }

    private function order(int $quantity = 1, ?array $items = null): Order
    {
        return app(OrderService::class)->saveAndReserve($this->orderData($quantity, $items), $this->owner);
    }

    private function fulfill(Order $order, ?string $key = null): Order
    {
        return app(OrderService::class)->fulfill($order, $key ?? (string) str()->uuid(), $this->owner);
    }

    private function scan(Order $order, string $code): array
    {
        $item = $order->items()->whereHas('product', fn ($query) => $query->where('condition', ProductCondition::Renewed->value))->orderBy('id')->firstOrFail();

        return $this->dispatch->scan($order, $item->id, $code, $this->owner);
    }

    private function certify(string $serial = 'DISPATCH-UNIT', array $changes = [], array $final = []): QcCertificate
    {
        $inspection = app(QcInspectionService::class)->start($changes + ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'serial' => $serial, 'features' => [], 'idempotency_key' => (string) str()->uuid()], $this->owner);

        return $this->finish($inspection, $final);
    }

    private function finish(QcInspection $inspection, array $final = []): QcCertificate
    {
        $service = app(QcInspectionService::class);
        $configuration = $final + ['cpu' => 'Intel Core i5', 'ram_mb' => 8192, 'storage_gb' => 256, 'os' => 'Windows 11'];
        $service->update($inspection, ['grade' => 'A', 'final_configuration' => $configuration], $this->owner);
        $checks = $inspection->checks()->get()->where('applicable', true)->mapWithKeys(fn ($check) => [$check->check_key => ['result' => 'pass'] + ($check->definition['measurement'] ? ['measurement' => $check->check_key === 'temperature' ? 70 : 90] : [])])->all();
        $service->update($inspection, ['checks' => $checks], $this->owner);
        foreach (['serial', 'physical', 'display', 'system', ...(($configuration['ram_mb'] !== 8192 || $configuration['storage_gb'] !== 256 || $inspection->requested_configuration) ? ['upgrade'] : [])] as $kind) {
            app(QcEvidenceService::class)->upload($inspection, UploadedFile::fake()->image($kind.'.jpg', 800, 600), $kind, true, $this->owner);
        }

        return $service->complete($inspection->fresh(), $this->owner);
    }

    private function upgrade(OrderItem $item): void
    {
        $config = SalesConfiguration::query()->create(['product_id' => $item->product_id, 'hardware_profile_version' => 1, 'display_name' => '16GB / 512GB', 'target_ram_mb' => 16384, 'target_storage_total_gb' => 512, 'target_storage_layout' => [], 'created_by_user_id' => $this->owner->id, 'updated_by_user_id' => $this->owner->id]);
        $recipe = UpgradeRecipe::query()->create(['sales_configuration_id' => $config->id, 'hardware_profile_version' => 1, 'name' => 'QC dispatch fixture', 'created_by_user_id' => $this->owner->id, 'updated_by_user_id' => $this->owner->id]);
        OrderItemUpgradeSelection::query()->create(['order_item_id' => $item->id, 'sales_configuration_id' => $config->id, 'upgrade_recipe_id' => $recipe->id, 'hardware_profile_version' => 1, 'configuration_snapshot' => ['target_ram_mb' => 16384, 'target_storage_total_gb' => 512], 'recipe_snapshot' => [], 'recovery_snapshot' => [], 'selected_by_user_id' => $this->owner->id]);
    }

    private function dispatchStaff(bool $scoped = true): User
    {
        $staff = $this->foundation['employee']->user;
        foreach ([QcPermission::View, QcPermission::ViewOrderAssignments, QcPermission::AssignOrderDevice, QcPermission::ViewDispatchQueue, QcPermission::ScanDispatch, QcPermission::ShipDispatch, OrderPermission::Fulfill] as $permission) {
            $this->override($staff, $permission, EmployeePermissionEffect::Allow);
        }
        if ($scoped) {
            app(ResponsibilityAssignmentService::class)->create($this->assignmentData($this->foundation), $this->owner);
        }

        return $staff;
    }

    private function override(User $user, \BackedEnum $permission, EmployeePermissionEffect $effect): void
    {
        app(EmployeePermissionOverrideService::class)->change($user->employee, $permission->value, $effect, 'Disposable QC test permission', $this->owner);
    }

    private function mobile(User $actor): void
    {
        $email = 'qc-mobile-'.$actor->id.'@techpointzone.com';
        $actor->update(['email' => $email]);
        $actor->employee->update(['email' => $email]);
        $this->withToken($actor->createToken('QC Dispatch Test')->plainTextToken);
    }

    private function reject(callable $operation, string $field): string
    {
        try {
            $operation();
            $this->fail('Expected validation rejection.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());

            return collect($e->errors())->flatten()->first();
        }
    }

    private function denied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected authorization denial.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }

    private function stockSnapshot(): array
    {
        return collect(['orders', 'order_items', 'product_inventories', 'inventory_reservations', 'inventory_allocation_balances', 'inventory_allocation_events', 'inventory_allocation_reservation_lines', 'stock_movements', 'order_fulfillments', 'order_fulfillment_items', 'order_upgrade_executions', 'order_upgrade_execution_lines'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }
}
