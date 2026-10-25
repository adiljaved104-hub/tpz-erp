<?php

namespace Tests\Feature\Returns;

use App\Actions\Orders\SaveAsShippedOrder;
use App\Actions\Returns\CreateCustomerReturn;
use App\Actions\Returns\ReceiveCustomerReturn;
use App\Contracts\EmployeePermissionOverrideResolver;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\DTOs\Returns\CreateCustomerReturnData;
use App\DTOs\Returns\InspectCustomerReturnItemData;
use App\Enums\CustomerReturnStatus;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\QcPermission;
use App\Exceptions\CustomerReturnException;
use App\Jobs\LabelCustomerReturnVideo;
use App\Models\ActivityLog;
use App\Models\CustomerReturnEvidence;
use App\Models\DamagedStockEvent;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentProduct;
use App\Models\SafetClaim;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Returns\CustomerReturnEvidenceAuthorization;
use App\Services\Returns\CustomerReturnEvidenceService;
use App\Services\Returns\CustomerReturnService;
use App\Services\Returns\CustomerReturnVideoDuration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CustomerReturnEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_wrong_item_evidence_is_private_retry_safe_and_cannot_post_original_inventory(): void
    {
        [$owner, $return, $inventory] = $this->receivedReturn();
        $item = $return->items()->firstOrFail();
        $before = StockMovement::query()->count();
        $input = ['request_id' => (string) Str::uuid(), 'upload_id' => (string) Str::uuid(),
            'problem_type' => 'wrong_item', 'actual_product_name' => 'Different laptop',
            'actual_serial_number' => 'OTHER-001', 'remarks' => 'Shipped item not returned'];
        $file = UploadedFile::fake()->image('received.jpg');
        $first = app(CustomerReturnEvidenceService::class)->record($item, $owner, $input, $file);
        $retry = app(CustomerReturnEvidenceService::class)->record($item, $owner, $input, $file);

        $this->assertSame($first->id, $retry->id);
        $this->assertDatabaseCount('customer_return_findings', 1);
        $this->assertDatabaseCount('customer_return_evidence', 1);
        $this->assertTrue(Storage::disk('local')->exists($first->original_path));
        $this->assertSame($before, StockMovement::query()->count());
        $this->assertSame(1, $inventory->refresh()->qc_pending_quantity);
        $this->assertSame(0, $inventory->available_quantity);
        try {
            app(CustomerReturnService::class)->inspect($item, new InspectCustomerReturnItemData(1, 0, (string) Str::uuid()), $owner);
            $this->fail('A wrong item must never restore the originally sold item.');
        } catch (CustomerReturnException $exception) {
            $this->assertStringContainsString('wrong or unverified', $exception->getMessage());
        }
        $this->assertSame($before, StockMovement::query()->count());
    }

    public function test_serial_mismatch_blocks_both_sellable_and_damaged_original_product_posting(): void
    {
        [$owner, $return, $inventory] = $this->receivedReturn();
        $item = $return->items()->firstOrFail();
        app(CustomerReturnEvidenceService::class)->record($item, $owner, [
            'request_id' => (string) Str::uuid(), 'upload_id' => (string) Str::uuid(),
            'problem_type' => 'serial_mismatch', 'actual_serial_number' => 'NOT-SHIPPED-123',
        ], UploadedFile::fake()->image('returned-serial.jpg'));
        $movements = StockMovement::query()->count();

        foreach ([[1, 0], [0, 1]] as [$sellable, $damaged]) {
            try {
                app(CustomerReturnService::class)->inspect($item, new InspectCustomerReturnItemData($sellable, $damaged, (string) Str::uuid()), $owner);
                $this->fail('A serial mismatch must not post the originally sold product.');
            } catch (CustomerReturnException $exception) {
                $this->assertStringContainsString('wrong or unverified', $exception->getMessage());
            }
        }

        $this->assertSame($movements, StockMovement::query()->count());
        $this->assertSame(1, $inventory->refresh()->qc_pending_quantity);
    }

    public function test_received_qc_pending_return_is_searchable_and_an_unprivileged_employee_cannot_upload_or_download(): void
    {
        [$owner, $return] = $this->receivedReturn();
        $item = $return->items()->firstOrFail();
        $evidence = app(CustomerReturnEvidenceService::class)->record($item, $owner, [
            'request_id' => (string) Str::uuid(), 'upload_id' => (string) Str::uuid(),
            'problem_type' => 'damaged', 'physical_condition' => 'Broken screen',
        ], UploadedFile::fake()->image('screen.jpg'));

        $this->withToken($owner->createToken('Return evidence test')->plainTextToken)
            ->getJson('/api/mobile/v1/workspace/qc/return-evidence?q='.$return->order_id)
            ->assertOk()->assertJsonFragment(['return_reference' => $return->reference]);
        $staff = User::factory()->create(['email' => 'return-evidence-staff@techpointzone.com']);
        Employee::factory()->for($staff)->role(EmployeeRole::Staff)->create(['email' => $staff->email]);
        $this->assertFalse(app(CustomerReturnEvidenceAuthorization::class)->canView($staff, $return));
        $this->app['auth']->forgetGuards();
        $this->withToken($staff->createToken('Return evidence denied test')->plainTextToken)
            ->getJson('/api/mobile/v1/workspace/qc/return-evidence/files/'.$evidence->id)->assertForbidden();
        $this->getJson('/api/mobile/v1/workspace/qc/return-evidence/dashboard')->assertForbidden();
        $this->postJson('/api/mobile/v1/workspace/qc/return-evidence/'.$return->id.'/items/'.$item->id, [])->assertForbidden();
    }

    public function test_mobile_dashboard_counts_scoped_return_items_without_double_counting_and_reports_authoritative_qc(): void
    {
        [$owner, $return] = $this->receivedReturn();
        $item = $return->items()->firstOrFail();
        $this->withToken($owner->createToken('Return dashboard')->plainTextToken);
        $this->getJson('/api/mobile/v1/workspace/qc/return-evidence/dashboard')
            ->assertOk()->assertJsonPath('returns_waiting_for_evidence', 1)
            ->assertJsonPath('evidence_uploaded', 0)
            ->assertJsonPath('pending.data.0.item_id', $item->id)
            ->assertJsonPath('pending.data.0.qc_result', null);

        $this->post('/api/mobile/v1/workspace/qc/return-evidence/'.$return->id.'/items/'.$item->id, [
            'request_id' => (string) Str::uuid(), 'upload_id' => (string) Str::uuid(),
            'problem_type' => 'sellable', 'file' => UploadedFile::fake()->image('first.jpg'),
        ])->assertOk();
        $this->post('/api/mobile/v1/workspace/qc/return-evidence/'.$return->id.'/items/'.$item->id, [
            'request_id' => (string) Str::uuid(), 'upload_id' => (string) Str::uuid(),
            'problem_type' => 'sellable', 'file' => UploadedFile::fake()->image('second.jpg'),
        ])->assertOk();
        $this->getJson('/api/mobile/v1/workspace/qc/return-evidence/dashboard')
            ->assertOk()->assertJsonPath('returns_waiting_for_evidence', 0)
            ->assertJsonPath('evidence_uploaded', 1);

        app(CustomerReturnService::class)->inspect($item, new InspectCustomerReturnItemData(1, 0, (string) Str::uuid()), $owner);
        $this->getJson('/api/mobile/v1/workspace/qc/return-evidence/'.$return->id)
            ->assertOk()->assertJsonPath('data.items.0.qc_result', 'sellable')
            ->assertJsonPath('data.items.0.inspected_quantity', 1)
            ->assertJsonPath('data.items.0.sellable_quantity', 1)
            ->assertJsonPath('data.items.0.damaged_quantity', 0)
            ->assertJsonMissingPath('data.customer_name');
    }

    public function test_device_capture_metadata_creates_a_separate_labeled_photo_without_changing_original(): void
    {
        [$owner, $return] = $this->receivedReturn();
        $item = $return->items()->firstOrFail();
        $input = [
            'request_id' => (string) Str::uuid(), 'upload_id' => (string) Str::uuid(),
            'problem_type' => 'damaged', 'captured_at_device' => '2026-10-24T12:34:56+05:00',
            'capture_timezone' => 'Asia/Karachi',
        ];
        $file = UploadedFile::fake()->image('damage.jpg', 800, 600);
        $evidence = app(CustomerReturnEvidenceService::class)->record($item, $owner, $input, $file);
        $label = $evidence->label()->firstOrFail();
        $this->assertSame(hash_file('sha256', Storage::disk('local')->path($evidence->original_path)), $evidence->sha256);
        $this->assertNotSame($evidence->sha256, $label->sha256);
        $this->assertTrue(Storage::disk('local')->exists($label->path));
        $this->assertSame([800, 720], array_slice(getimagesize(Storage::disk('local')->path($label->path)), 0, 2));
        $this->assertSame('Asia/Karachi', $evidence->capture_timezone);
        $this->assertSame($evidence->id, app(CustomerReturnEvidenceService::class)->record($item, $owner, $input, $file)->id);
        $this->assertDatabaseCount('customer_return_evidence_labels', 1);
        $this->actingAs($owner)->get('/admin/customer-return-evidence/'.$evidence->id.'/labeled')->assertOk();
    }

    public function test_original_wrong_item_reason_and_damaged_evidence_finding_remain_distinct_without_inventory_posting(): void
    {
        [$owner, $return, $inventory] = $this->receivedReturn('wrong_item');
        $item = $return->items()->firstOrFail();
        $movements = StockMovement::query()->count();
        app(CustomerReturnEvidenceService::class)->record($item, $owner, [
            'request_id' => (string) Str::uuid(), 'upload_id' => (string) Str::uuid(),
            'problem_type' => 'damaged', 'actual_product_name' => 'Different laptop',
            'physical_condition' => 'Cracked screen',
        ], UploadedFile::fake()->image('different-damaged.jpg'));

        $this->withToken($owner->createToken('Classification test')->plainTextToken)
            ->getJson('/api/mobile/v1/workspace/qc/return-evidence/'.$return->id)
            ->assertOk()->assertJsonPath('data.items.0.return_reason', 'wrong_item')
            ->assertJsonPath('data.items.0.findings.0.problem_type', 'damaged')
            ->assertJsonPath('data.items.0.findings.0.actual_product_name', 'Different laptop')
            ->assertJsonPath('data.items.0.findings.0.physical_condition', 'Cracked screen');
        $this->assertSame($movements, StockMovement::query()->count());
        $this->assertSame(1, $inventory->refresh()->qc_pending_quantity);
    }

    public function test_video_must_respect_duration_and_does_not_use_renewed_qc_evidence(): void
    {
        [$owner, $return] = $this->receivedReturn();
        $item = $return->items()->firstOrFail();
        $input = ['request_id' => (string) Str::uuid(), 'upload_id' => (string) Str::uuid(), 'problem_type' => 'missing_accessories'];
        $video = UploadedFile::fake()->create('unboxing.mp4', 100, 'video/mp4');
        $this->expectException(ValidationException::class);
        app(CustomerReturnEvidenceService::class)->record($item, $owner, $input + ['duration_seconds' => 999], $video);
    }

    public function test_video_header_duration_is_verified_and_the_original_is_retained(): void
    {
        [$owner, $return] = $this->receivedReturn();
        $item = $return->items()->firstOrFail();
        $movieHeader = pack('N', 24).'ftypisom'.pack('N', 0).'isommp42';
        $mvhd = pack('N', 28).'mvhd'.pack('N', 0).pack('N', 0).pack('N', 0).pack('N', 1000).pack('N', 7000);
        $movie = $movieHeader.pack('N', strlen($mvhd) + 8).'moov'.$mvhd;
        $video = UploadedFile::fake()->createWithContent('received.mp4', $movie);
        $video->mimeTypeToReport = 'video/mp4';
        $this->assertSame(7, app(CustomerReturnVideoDuration::class)->seconds($video->getRealPath()));

        $evidence = app(CustomerReturnEvidenceService::class)->record($item, $owner, [
            'request_id' => (string) Str::uuid(), 'upload_id' => (string) Str::uuid(),
            'problem_type' => 'damaged', 'duration_seconds' => 7,
        ], $video);
        $this->assertSame('video', $evidence->media_type);
        $this->assertSame($movie, Storage::disk('local')->get($evidence->original_path));
    }

    public function test_video_label_is_queued_without_blocking_upload_or_changing_the_original(): void
    {
        [$owner, $return] = $this->receivedReturn();
        Queue::fake();
        config(['customer_return_evidence.ffmpeg_binary' => 'ffmpeg', 'queue.default' => 'database']);
        $movieHeader = pack('N', 24).'ftypisom'.pack('N', 0).'isommp42';
        $mvhd = pack('N', 28).'mvhd'.pack('N', 0).pack('N', 0).pack('N', 0).pack('N', 1000).pack('N', 7000);
        $movie = $movieHeader.pack('N', strlen($mvhd) + 8).'moov'.$mvhd;
        $video = UploadedFile::fake()->createWithContent('received.mp4', $movie);
        $video->mimeTypeToReport = 'video/mp4';
        $evidence = app(CustomerReturnEvidenceService::class)->record($return->items()->firstOrFail(), $owner, [
            'request_id' => (string) Str::uuid(), 'upload_id' => (string) Str::uuid(),
            'problem_type' => 'damaged', 'duration_seconds' => 7,
            'captured_at_device' => '2026-10-24T12:34:56+05:00', 'capture_timezone' => 'Asia/Karachi',
        ], $video);

        Queue::assertPushed(LabelCustomerReturnVideo::class, fn (LabelCustomerReturnVideo $job) => $job->evidenceId === $evidence->id);
        $this->assertNull($evidence->label);
        $this->assertSame($movie, Storage::disk('local')->get($evidence->original_path));
    }

    public function test_same_upload_id_cannot_represent_different_evidence(): void
    {
        [$owner, $return] = $this->receivedReturn();
        $item = $return->items()->firstOrFail();
        $input = ['request_id' => (string) Str::uuid(), 'upload_id' => (string) Str::uuid(), 'problem_type' => 'damaged'];
        app(CustomerReturnEvidenceService::class)->record($item, $owner, $input, UploadedFile::fake()->image('first.jpg'));

        $this->expectException(ValidationException::class);
        app(CustomerReturnEvidenceService::class)->record($item, $owner, $input, UploadedFile::fake()->image('different.jpg', 20, 20));
    }

    public function test_authorized_mobile_upload_and_web_gallery_file_are_private(): void
    {
        [$owner, $return] = $this->receivedReturn();
        $item = $return->items()->firstOrFail();
        $this->withToken($owner->createToken('Return mobile upload')->plainTextToken)
            ->post('/api/mobile/v1/workspace/qc/return-evidence/'.$return->id.'/items/'.$item->id, [
                'request_id' => (string) Str::uuid(), 'upload_id' => (string) Str::uuid(),
                'problem_type' => 'serial_mismatch', 'actual_serial_number' => 'RETURN-002',
                'file' => UploadedFile::fake()->image('serial.jpg'),
            ])->assertOk()->assertJsonFragment(['media_type' => 'photo']);

        $evidence = CustomerReturnEvidence::query()->firstOrFail();
        $this->assertTrue(Storage::disk('local')->exists($evidence->original_path));
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '')->get('/admin/customer-return-evidence/'.$evidence->id.'/original')->assertUnauthorized();
        $this->actingAs($owner)->get('/admin/customer-return-evidence/'.$evidence->id.'/preview')->assertOk();
    }

    public function test_completed_return_accepts_append_only_mobile_evidence_and_flags_conflicts_without_reposting_stock(): void
    {
        [$owner, $return, $inventory] = $this->receivedReturn();
        $item = $return->items()->firstOrFail();
        app(CustomerReturnService::class)->inspect($item, new InspectCustomerReturnItemData(1, 0, (string) Str::uuid()), $owner);
        $this->assertSame(CustomerReturnStatus::Completed, $return->refresh()->status);
        $movements = StockMovement::query()->count();
        $inspectionCount = $item->inspections()->count();

        $this->withToken($owner->createToken('Completed return evidence')->plainTextToken);
        foreach (['sellable', 'wrong_item'] as $problem) {
            $this->post('/api/mobile/v1/workspace/qc/return-evidence/'.$return->id.'/items/'.$item->id, [
                'request_id' => (string) Str::uuid(), 'upload_id' => (string) Str::uuid(),
                'problem_type' => $problem, 'remarks' => 'Additional evidence after QC',
                'file' => UploadedFile::fake()->image($problem.'.jpg'),
            ])->assertOk();
        }

        $this->assertDatabaseCount('customer_return_findings', 2);
        $this->assertDatabaseCount('customer_return_evidence', 2);
        $this->assertSame($inspectionCount, $item->inspections()->count());
        $this->assertSame($movements, StockMovement::query()->count());
        $this->assertSame(0, DamagedStockEvent::query()->count());
        $this->assertSame(0, SafetClaim::query()->count());
        $this->assertSame(1, $inventory->refresh()->available_quantity);
        $this->assertSame(0, $inventory->damaged_quantity);
        $this->assertNotNull($item->fresh()->qcEvidenceReviewReason());
        $this->getJson('/api/mobile/v1/workspace/qc/return-evidence/dashboard')
            ->assertOk()->assertJsonPath('returns_needing_review', 1);
        $log = ActivityLog::query()->where('event', 'customer_return.evidence_uploaded')->latest('id')->firstOrFail();
        $this->assertTrue($log->properties['qc_review_required']);

    }

    public function test_technician_can_use_only_the_return_evidence_qc_endpoint_without_order_or_financial_data(): void
    {
        [, $return] = $this->receivedReturn();
        $technician = User::factory()->create(['email' => 'return-evidence-tech@techpointzone.com']);
        Employee::factory()->for($technician)->role(EmployeeRole::Technician)->create(['email' => $technician->email]);
        $this->withToken($technician->createToken('Return QC test')->plainTextToken);

        $this->getJson('/api/mobile/v1/workspace/qc/return-evidence?q='.$return->order_id)
            ->assertOk()->assertJsonFragment(['return_reference' => $return->reference]);
        $this->getJson('/api/mobile/v1/workspace/qc/return-evidence/'.$return->id)
            ->assertOk()->assertJsonMissingPath('data.customer_name')->assertJsonMissingPath('data.grand_total');
        $this->getJson('/api/mobile/v1/workspace/returns')->assertForbidden();
    }

    public function test_staff_qc_permission_does_not_bypass_return_responsibility_scope(): void
    {
        [$owner, $return] = $this->receivedReturn();
        $staffUser = User::factory()->create(['email' => 'scoped-return-qc@techpointzone.com']);
        $staff = Employee::factory()->for($staffUser)->role(EmployeeRole::Staff)->create(['email' => $staffUser->email]);
        foreach ([QcPermission::View, QcPermission::ViewReturnEvidence, QcPermission::RecordReturnEvidence] as $permission) {
            EmployeePermissionOverride::query()->create([
                'employee_id' => $staff->id, 'permission_key' => $permission->value,
                'effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $owner->id,
                'reason' => 'Scoped return QC test',
            ]);
        }
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($staff->id);
        $authorization = app(CustomerReturnEvidenceAuthorization::class);
        $this->assertFalse($authorization->canRecord($staffUser, $return));
        $this->assertFalse($authorization->canView($staffUser, $return));

        $assignment = ResponsibilityAssignment::factory()->create([
            'employee_id' => $staff->id, 'assigned_by_user_id' => $owner->id,
        ]);
        ResponsibilityAssignmentProduct::query()->create([
            'assignment_id' => $assignment->id, 'product_id' => $return->items()->firstOrFail()->product_id,
        ]);
        $this->assertTrue($authorization->canRecord($staffUser, $return));
        $this->assertTrue($authorization->canView($staffUser, $return));
    }

    private function receivedReturn(string $reason = 'other'): array
    {
        $owner = User::factory()->create(['email' => 'return-evidence-owner@techpointzone.com']);
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create(['status' => true, 'is_default' => true]);
        $inventory = ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'available_quantity' => 1, 'average_cost' => '100.0000']);
        $order = app(SaveAsShippedOrder::class)->handle(new SaveAndReserveOrderData($warehouse->id, null, null, now()->toDateString(),
            $owner->employee->id, null, [new OrderItemData($product->id, 1, '500.00')], (string) Str::uuid()), $owner)->load('fulfillment.items');
        $return = app(CreateCustomerReturn::class)->handle(new CreateCustomerReturnData($order->id, $warehouse->id,
            [['order_fulfillment_item_id' => $order->fulfillment->items->first()->id, 'quantity' => 1, 'return_reason' => $reason]],
            (string) Str::uuid()), $owner);
        app(ReceiveCustomerReturn::class)->handle($return, $owner);

        return [$owner, $return->refresh(), $inventory];
    }
}
