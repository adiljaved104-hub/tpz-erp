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
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\QcPermission;
use App\Exceptions\CustomerReturnException;
use App\Models\CustomerReturnEvidence;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentProduct;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Returns\CustomerReturnEvidenceAuthorization;
use App\Services\Returns\CustomerReturnEvidenceService;
use App\Services\Returns\CustomerReturnService;
use App\Services\Returns\CustomerReturnVideoDuration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
        $this->postJson('/api/mobile/v1/workspace/qc/return-evidence/'.$return->id.'/items/'.$item->id, [])->assertForbidden();
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

    private function receivedReturn(): array
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
            [['order_fulfillment_item_id' => $order->fulfillment->items->first()->id, 'quantity' => 1, 'return_reason' => 'other']],
            (string) Str::uuid()), $owner);
        app(ReceiveCustomerReturn::class)->handle($return, $owner);

        return [$owner, $return->refresh(), $inventory];
    }
}
