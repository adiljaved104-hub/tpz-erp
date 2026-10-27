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
use App\Jobs\FinalizeCustomerReturnVideo;
use App\Jobs\LabelCustomerReturnPhoto;
use App\Jobs\LabelCustomerReturnVideo;
use App\Models\ActivityLog;
use App\Models\CustomerReturnEvidence;
use App\Models\CustomerReturnVideoUploadSession;
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
use App\Services\Returns\CustomerReturnEvidenceLabelService;
use App\Services\Returns\CustomerReturnEvidenceService;
use App\Services\Returns\CustomerReturnService;
use App\Services\Returns\CustomerReturnVideoDuration;
use App\Services\Returns\CustomerReturnVideoUploadService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
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
        $this->assertSame('2026-10-24 07:34:56', $evidence->getRawOriginal('captured_at_device'));
        $layout = (new \ReflectionMethod(CustomerReturnEvidenceLabelService::class, 'layout'))
            ->invoke(app(CustomerReturnEvidenceLabelService::class), $evidence->fresh(), 800, 600);
        $this->assertSame('24-10-2026 Sat 12:34:56 PKT', $layout['stamp']);
        $this->assertSame(hash_file('sha256', Storage::disk('local')->path($evidence->original_path)), $evidence->sha256);
        $this->assertNotSame($evidence->sha256, $label->sha256);
        $this->assertTrue(Storage::disk('local')->exists($label->path));
        $this->assertSame([800, 600], array_slice(getimagesize(Storage::disk('local')->path($label->path)), 0, 2));
        $this->assertSame('Asia/Karachi', $evidence->capture_timezone);
        $this->assertSame($evidence->id, app(CustomerReturnEvidenceService::class)->record($item, $owner, $input, $file)->id);
        $this->assertDatabaseCount('customer_return_evidence_labels', 1);
        $this->actingAs($owner)->get('/admin/customer-return-evidence/'.$evidence->id.'/labeled')->assertOk();
        $gallery = view('returns.evidence-gallery', ['getRecord' => fn () => $return])->render();
        $this->assertStringContainsString('tpz-return-gallery-grid', $gallery);
        $this->assertStringContainsString('Open larger preview', $gallery);
        $this->assertStringContainsString('Download original', $gallery);
        $this->assertStringContainsString('Download labeled copy', $gallery);
        $this->assertStringContainsString('Original SHA-256', $gallery);
        $this->assertStringContainsString($evidence->sha256, $gallery);
        $this->assertStringContainsString('24 Oct 2026, 12:34:56', $gallery);
    }

    public function test_utc_capture_instant_displays_in_pakistan_and_uae_across_midnight(): void
    {
        [$owner, $return] = $this->receivedReturn();
        $item = $return->items()->firstOrFail();
        foreach ([
            ['2026-10-26T19:30:00Z', 'Asia/Karachi', '27-10-2026 Tue 00:30:00 PKT'],
            ['2026-10-26T21:30:00Z', 'Asia/Dubai', '27-10-2026 Tue 01:30:00 +04'],
        ] as [$inputTime, $zone, $expected]) {
            $evidence = app(CustomerReturnEvidenceService::class)->record($item, $owner, [
                'request_id' => (string) Str::uuid(), 'upload_id' => (string) Str::uuid(),
                'problem_type' => 'damaged', 'captured_at_device' => $inputTime,
                'capture_timezone' => $zone,
            ], $this->syntheticLaptopPhoto(800, 600));
            $this->assertSame(substr($inputTime, 0, 10).' '.substr($inputTime, 11, 8), $evidence->getRawOriginal('captured_at_device'));
            $layout = (new \ReflectionMethod(CustomerReturnEvidenceLabelService::class, 'layout'))
                ->invoke(app(CustomerReturnEvidenceLabelService::class), $evidence->fresh(), 800, 600);
            $this->assertSame($expected, $layout['stamp']);
            $this->assertSame($expected, $evidence->deviceCaptureTime()?->format('d-m-Y D H:i:s T'));
        }
    }

    public function test_video_probe_accepts_android_rotation_metadata_without_trailing_csv_separator_failure(): void
    {
        $parse = new \ReflectionMethod(CustomerReturnEvidenceLabelService::class, 'parseVideoDimensions');
        $labels = app(CustomerReturnEvidenceLabelService::class);
        $portrait = '{"streams":[{"width":1920,"height":1080,"side_data_list":[{"side_data_type":"Display Matrix","rotation":-90}]}]}';
        $landscape = '{"streams":[{"width":1920,"height":1080}]}';

        $this->assertSame([1080, 1920], $parse->invoke($labels, $portrait));
        $this->assertSame([1920, 1080], $parse->invoke($labels, $landscape));

        foreach (['1920x1080x', '{"streams":[{"width":0,"height":1080}]}', '{"streams":[]}'] as $invalid) {
            try {
                $parse->invoke($labels, $invalid);
                $this->fail('Unverified probe output must not be accepted.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Video dimensions could not be verified.', $exception->getMessage());
            }
        }
    }

    public function test_local_video_probe_accounts_for_rotated_mp4_display_matrix(): void
    {
        $binary = getenv('TPZ_TEST_FFMPEG_BINARY');
        if (! is_string($binary) || ! is_file($binary)) {
            $this->markTestSkipped('Set TPZ_TEST_FFMPEG_BINARY to check rotated MP4 dimensions with local FFmpeg.');
        }
        $probe = dirname($binary).DIRECTORY_SEPARATOR.'ffprobe.exe';
        if (! is_file($probe)) {
            $this->markTestSkipped('The local FFmpeg distribution does not include ffprobe.');
        }

        $directory = 'synthetic-rotated-return-video';
        Storage::disk('local')->makeDirectory($directory);
        $coded = Storage::disk('local')->path($directory.'/coded.mp4');
        $rotated = Storage::disk('local')->path($directory.'/rotated.mp4');
        (new Process([$binary, '-nostdin', '-hide_banner', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'color=c=blue:s=640x360:r=25:d=1',
            '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-f', 'mp4', $coded]))->mustRun();
        (new Process([$binary, '-nostdin', '-hide_banner', '-loglevel', 'error',
            '-i', $coded, '-c', 'copy', '-metadata:s:v:0', 'rotate=90', $rotated]))->mustRun();
        $originalHash = hash_file('sha256', $rotated);

        $dimensions = (new \ReflectionMethod(CustomerReturnEvidenceLabelService::class, 'videoDimensions'))
            ->invoke(app(CustomerReturnEvidenceLabelService::class), $binary, $rotated);

        $this->assertSame([360, 640], $dimensions);
        $this->assertSame($originalHash, hash_file('sha256', $rotated));
    }

    public function test_photo_label_runs_on_dedicated_queue_without_losing_original_or_creating_duplicates(): void
    {
        [$owner, $return] = $this->receivedReturn();
        Queue::fake();
        config(['queue.default' => 'database']);
        $evidence = app(CustomerReturnEvidenceService::class)->record($return->items()->firstOrFail(), $owner, [
            'request_id' => (string) Str::uuid(), 'upload_id' => (string) Str::uuid(),
            'problem_type' => 'damaged', 'captured_at_device' => '2026-10-26T19:30:00Z',
            'capture_timezone' => 'Asia/Karachi',
        ], $this->syntheticLaptopPhoto(800, 600));
        Queue::assertPushed(LabelCustomerReturnPhoto::class, fn (LabelCustomerReturnPhoto $job) => $job->evidenceId === $evidence->id && $job->connection === 'return_evidence_labels');
        $this->assertNull($evidence->label);
        $original = Storage::disk('local')->get($evidence->original_path);
        $job = new LabelCustomerReturnPhoto($evidence->id);
        $job->handle(app(CustomerReturnEvidenceLabelService::class));
        $job->handle(app(CustomerReturnEvidenceLabelService::class));
        $this->assertDatabaseCount('customer_return_evidence_labels', 1);
        $this->assertSame($original, Storage::disk('local')->get($evidence->original_path));
        $this->assertNotNull($evidence->refresh()->readableLabel());
    }

    public function test_dedicated_worker_labels_a_3060_by_4080_photo_and_preserves_its_original(): void
    {
        [$owner, $return] = $this->receivedReturn();
        Queue::fake();
        config(['queue.default' => 'database']);
        $evidence = app(CustomerReturnEvidenceService::class)->record($return->items()->firstOrFail(), $owner, [
            'request_id' => (string) Str::uuid(), 'upload_id' => (string) Str::uuid(),
            'problem_type' => 'damaged', 'captured_at_device' => '2026-10-27T04:29:39Z',
            'capture_timezone' => 'Asia/Karachi',
        ], UploadedFile::fake()->image('high-resolution.jpg', 3060, 4080));
        Queue::assertPushed(LabelCustomerReturnPhoto::class, 1);
        $originalPath = Storage::disk('local')->path($evidence->original_path);
        $originalHash = hash_file('sha256', $originalPath);

        (new LabelCustomerReturnPhoto($evidence->id))->handle(app(CustomerReturnEvidenceLabelService::class));

        $label = $evidence->refresh()->readableLabel();
        $this->assertNotNull($label);
        $this->assertGreaterThan(0, filesize(Storage::disk('local')->path($label->path)));
        $this->assertSame($originalHash, hash_file('sha256', $originalPath));
        $this->assertSame($evidence->sha256, $originalHash);
        $this->assertDatabaseCount('customer_return_evidence_labels', 1);
    }

    public function test_missing_label_file_is_not_reported_ready_or_recreated_over_its_immutable_record(): void
    {
        [$owner, $return] = $this->receivedReturn();
        $evidence = app(CustomerReturnEvidenceService::class)->record($return->items()->firstOrFail(), $owner, [
            'request_id' => (string) Str::uuid(), 'upload_id' => (string) Str::uuid(),
            'problem_type' => 'damaged', 'captured_at_device' => '2026-10-26T19:30:00Z',
            'capture_timezone' => 'Asia/Karachi',
        ], $this->syntheticLaptopPhoto(800, 600));
        $label = $evidence->label()->firstOrFail();
        Storage::disk('local')->delete($label->path);

        $this->actingAs($owner);
        $gallery = view('returns.evidence-gallery', ['getRecord' => fn () => $return])->render();
        $this->assertStringContainsString('Labeled copy not ready', $gallery);
        $this->assertStringNotContainsString('Download labeled copy', $gallery);
        $this->get('/admin/customer-return-evidence/'.$evidence->id.'/preview')->assertOk();
        $this->get('/admin/customer-return-evidence/'.$evidence->id.'/labeled')->assertNotFound();
        $this->assertNull($evidence->readableLabel());
        app(CustomerReturnEvidenceLabelService::class)->createIfSupported($evidence);
        $this->assertDatabaseCount('customer_return_evidence_labels', 1);
        $this->assertSame($label->path, $evidence->label()->firstOrFail()->path);
        $this->assertSame($evidence->sha256, hash_file('sha256', Storage::disk('local')->path($evidence->original_path)));
    }

    public function test_guarded_photo_recovery_is_dry_run_by_default_and_rejects_changed_originals(): void
    {
        [$owner, $return] = $this->receivedReturn();
        Queue::fake();
        config(['queue.default' => 'database']);
        $photo = app(CustomerReturnEvidenceService::class)->record($return->items()->firstOrFail(), $owner, [
            'request_id' => (string) Str::uuid(), 'upload_id' => (string) Str::uuid(),
            'problem_type' => 'damaged', 'captured_at_device' => '2026-10-26T19:30:00Z',
            'capture_timezone' => 'Asia/Karachi',
        ], $this->syntheticLaptopPhoto(800, 600));
        Queue::fake();
        $options = ['returnId' => $return->id, '--evidence' => (string) $photo->id];
        $this->artisan('returns:queue-photo-labels', $options)->assertExitCode(0);
        Queue::assertNothingPushed();

        $original = Storage::disk('local')->get($photo->original_path);
        Storage::disk('local')->put($photo->original_path, 'changed bytes');
        $this->artisan('returns:queue-photo-labels', $options + ['--execute' => true])->assertExitCode(1);
        Queue::assertNothingPushed();
        Storage::disk('local')->put($photo->original_path, $original);
        $this->artisan('returns:queue-photo-labels', $options + ['--execute' => true])->assertExitCode(0);
        Queue::assertPushed(LabelCustomerReturnPhoto::class, 1);
        $this->assertDatabaseCount('customer_return_evidence_labels', 0);
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

    public function test_shared_gallery_displays_photo_and_ordered_video_parts_with_expandable_metadata(): void
    {
        [$owner, $return] = $this->receivedReturn();
        $item = $return->items()->firstOrFail();
        Queue::fake();
        $metadata = ['problem_type' => 'damaged', 'captured_at_device' => '2026-10-26T12:01:21+05:00',
            'capture_timezone' => 'Asia/Karachi'];
        config(['queue.default' => 'sync']);
        app(CustomerReturnEvidenceService::class)->record($item, $owner, $metadata + [
            'request_id' => (string) Str::uuid(), 'upload_id' => (string) Str::uuid(),
        ], $this->syntheticLaptopPhoto(960, 540));
        config(['customer_return_evidence.ffmpeg_binary' => 'ffmpeg', 'queue.default' => 'database']);
        $header = pack('N', 24).'ftypisom'.pack('N', 0).'isommp42';
        $mvhd = pack('N', 28).'mvhd'.pack('N', 0).pack('N', 0).pack('N', 0).pack('N', 1000).pack('N', 7000);
        $movie = $header.pack('N', strlen($mvhd) + 8).'moov'.$mvhd;
        foreach ([1, 2] as $part) {
            $video = UploadedFile::fake()->createWithContent("part-$part.mp4", $movie);
            $video->mimeTypeToReport = 'video/mp4';
            app(CustomerReturnEvidenceService::class)->record($item, $owner, $metadata + [
                'request_id' => (string) Str::uuid(), 'upload_id' => (string) Str::uuid(),
                'duration_seconds' => 7,
            ], $video);
        }

        $this->actingAs($owner);
        $gallery = view('returns.evidence-gallery', ['getRecord' => fn () => $return])->render();
        $this->assertSame(3, substr_count($gallery, '<article class="tpz-return-gallery-card">'));
        $this->assertSame(1, substr_count($gallery, '<div class="tpz-return-gallery-grid">'));
        $this->assertStringContainsString('Video — Part 1', $gallery);
        $this->assertStringContainsString('Video — Part 2', $gallery);
        $this->assertStringContainsString('<details class="tpz-return-gallery-details">', $gallery);
        $this->assertStringContainsString('Download original', $gallery);
        $this->assertStringContainsString('Download labeled copy', $gallery);
        $this->assertStringContainsString('12:01:21', $gallery);
        $this->assertSame(1, substr_count($gallery, 'Labeled copy ready'));
        $this->assertSame(2, substr_count($gallery, 'Labeled copy not ready'));
        $this->assertSame(1, substr_count($gallery, 'Download labeled copy'));
        $this->assertMatchesRegularExpression('/Video — Part 1.*?Labeled copy not ready/s', $gallery);

        $claim = new SafetClaim;
        $claim->customer_return_item_id = $item->id;
        $claim->setRelation('customerReturn', $return);
        $claimGallery = view('returns.evidence-gallery', ['getRecord' => fn () => $claim])->render();
        $this->assertSame(3, substr_count($claimGallery, '<article class="tpz-return-gallery-card">'));
        $this->assertStringContainsString('Video — Part 2', $claimGallery);
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

    public function test_video_size_limit_rejects_oversize_before_creating_evidence(): void
    {
        [$owner, $return] = $this->receivedReturn();
        config(['customer_return_evidence.max_video_kib' => 1]);
        try {
            app(CustomerReturnEvidenceService::class)->record($return->items()->firstOrFail(), $owner, [
                'request_id' => (string) Str::uuid(), 'upload_id' => (string) Str::uuid(),
                'problem_type' => 'damaged', 'duration_seconds' => 1,
            ], UploadedFile::fake()->create('oversize.mp4', 2, 'video/mp4'));
            $this->fail('An oversized video must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }
        $this->assertDatabaseCount('customer_return_evidence', 0);
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

    public function test_five_minute_video_boundary_and_multiple_ordered_parts_preserve_originals(): void
    {
        [$owner, $return] = $this->receivedReturn();
        $item = $return->items()->firstOrFail();
        $this->assertSame(204800, config('customer_return_evidence.max_video_kib'));
        $this->assertSame(300, config('customer_return_evidence.max_video_seconds'));
        $header = pack('N', 24).'ftypisom'.pack('N', 0).'isommp42';
        $mvhd = pack('N', 28).'mvhd'.pack('N', 0).pack('N', 0).pack('N', 0).pack('N', 1000).pack('N', 300000);
        $movie = $header.pack('N', strlen($mvhd) + 8).'moov'.$mvhd;
        Queue::fake();
        config(['customer_return_evidence.ffmpeg_binary' => 'ffmpeg', 'queue.default' => 'database']);
        $saved = [];
        foreach ([1, 2] as $part) {
            $video = UploadedFile::fake()->createWithContent("part-$part.mp4", $movie);
            $video->mimeTypeToReport = 'video/mp4';
            $saved[] = app(CustomerReturnEvidenceService::class)->record($item, $owner, [
                'request_id' => (string) Str::uuid(), 'upload_id' => (string) Str::uuid(),
                'problem_type' => 'damaged', 'duration_seconds' => 300,
                'captured_at_device' => '2026-10-24T12:34:56+05:00', 'capture_timezone' => 'Asia/Karachi',
            ], $video);
        }
        Queue::assertPushed(LabelCustomerReturnVideo::class, 2);
        Queue::assertPushed(LabelCustomerReturnVideo::class,
            fn (LabelCustomerReturnVideo $job) => $job->connection === 'return_evidence_labels'
                && config('queue.connections.return_evidence_labels.retry_after') > $job->timeout);
        foreach ($saved as $evidence) {
            $this->assertSame($movie, Storage::disk('local')->get($evidence->original_path));
            $this->assertSame(300, $evidence->duration_seconds);
        }
        $this->withToken($owner->createToken('Video parts')->plainTextToken)
            ->getJson('/api/mobile/v1/workspace/qc/return-evidence/'.$return->id)
            ->assertOk()
            ->assertJsonPath('data.items.0.findings.0.evidence.0.video_part', 1)
            ->assertJsonPath('data.items.0.findings.1.evidence.0.video_part', 2);
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

    public function test_portrait_photo_label_keeps_original_dimensions_and_translucent_bands(): void
    {
        [$owner, $return] = $this->receivedReturn();
        $evidence = app(CustomerReturnEvidenceService::class)->record($return->items()->firstOrFail(), $owner, [
            'request_id' => (string) Str::uuid(), 'upload_id' => (string) Str::uuid(),
            'problem_type' => 'damaged', 'captured_at_device' => '2026-10-26T12:01:21+05:00',
            'capture_timezone' => 'Asia/Karachi',
        ], $this->syntheticLaptopPhoto(600, 900));
        $label = $evidence->label()->firstOrFail();
        $this->assertSame([600, 900], array_slice(getimagesize(Storage::disk('local')->path($label->path)), 0, 2));
        $this->assertSame($evidence->sha256, hash_file('sha256', Storage::disk('local')->path($evidence->original_path)));
        $derivative = imagecreatefromjpeg(Storage::disk('local')->path($label->path));
        $this->assertInstanceOf(\GdImage::class, $derivative);
        try {
            $top = imagecolorsforindex($derivative, imagecolorat($derivative, 590, 5));
            $this->assertGreaterThan(120, $top['red']);
            $this->assertLessThan(200, $top['red']);
            $this->assertGreaterThan(210, imagecolorsforindex($derivative, imagecolorat($derivative, 590, 450))['red']);
        } finally {
            imagedestroy($derivative);
        }
    }

    public function test_landscape_photo_uses_the_same_translucent_claim_copy_design(): void
    {
        [$owner, $return] = $this->receivedReturn();
        $evidence = app(CustomerReturnEvidenceService::class)->record($return->items()->firstOrFail(), $owner, [
            'request_id' => (string) Str::uuid(), 'upload_id' => (string) Str::uuid(),
            'problem_type' => 'damaged', 'captured_at_device' => '2026-10-26T12:01:21+05:00',
            'capture_timezone' => 'Asia/Karachi',
        ], $this->syntheticLaptopPhoto(960, 540));
        $label = $evidence->label()->firstOrFail();
        $this->assertSame([960, 540], array_slice(getimagesize(Storage::disk('local')->path($label->path)), 0, 2));
        $this->assertSame($evidence->sha256, hash_file('sha256', Storage::disk('local')->path($evidence->original_path)));
        $source = imagecreatefromjpeg(Storage::disk('local')->path($evidence->original_path));
        $derivative = imagecreatefromjpeg(Storage::disk('local')->path($label->path));
        try {
            $original = imagecolorsforindex($source, imagecolorat($source, 950, 5))['red'];
            $overlaid = imagecolorsforindex($derivative, imagecolorat($derivative, 950, 5))['red'];
            $this->assertEqualsWithDelta($original * .6, $overlaid, 12);
        } finally {
            imagedestroy($source);
            imagedestroy($derivative);
        }
    }

    public function test_video_filter_uses_compact_translucent_bands_and_device_reported_clock(): void
    {
        [$owner, $return] = $this->receivedReturn();
        $evidence = app(CustomerReturnEvidenceService::class)->record($return->items()->firstOrFail(), $owner, [
            'request_id' => (string) Str::uuid(), 'upload_id' => (string) Str::uuid(),
            'problem_type' => 'damaged', 'captured_at_device' => '2026-10-26T12:01:21+05:00',
            'capture_timezone' => 'Asia/Karachi',
        ], UploadedFile::fake()->image('layout.jpg', 600, 900));
        $service = app(CustomerReturnEvidenceLabelService::class);
        $layout = (new \ReflectionMethod($service, 'layout'))->invoke($service, $evidence, 600, 900);
        $filter = (new \ReflectionMethod($service, 'videoFilter'))->invoke($service, $layout, 600, 900, 'clock.txt');

        $this->assertStringContainsString('TECH POINT ZONE', $filter);
        $this->assertStringContainsString('CUSTOMER RETURN EVIDENCE', $filter);
        $this->assertStringContainsString('Return ID ', $filter);
        $this->assertStringContainsString('Order ID ', $filter);
        $this->assertStringContainsString('black@0.40', $filter);
        $this->assertStringContainsString('textfile=clock.txt', $filter);
        $this->assertSame('26-10-2026 Mon 12:01:21 PKT', $layout['stamp']);
        $this->assertSame('26-10-2026 Mon 12:01:21', gmdate('d-m-Y D H:i:s', $layout['wallEpoch']));
        $this->assertLessThan(900 * .42, $layout['footerHeight']);
    }

    public function test_local_ffmpeg_renders_the_capture_timestamp_without_changing_the_original(): void
    {
        $binary = getenv('TPZ_TEST_FFMPEG_BINARY');
        if (! is_string($binary) || ! is_file($binary)) {
            $this->markTestSkipped('Set TPZ_TEST_FFMPEG_BINARY to run the local synthetic-video filter check.');
        }

        $directory = 'synthetic-return-label';
        Storage::disk('local')->makeDirectory($directory);
        $source = Storage::disk('local')->path($directory.'/original.mp4');
        $labeled = Storage::disk('local')->path($directory.'/labeled.mp4');
        (new Process([$binary, '-nostdin', '-hide_banner', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'color=c=0xD6EAF8:s=640x360:r=25:d=2,drawbox=x=80:y=50:w=480:h=225:color=0x2F4257:t=fill,drawbox=x=95:y=65:w=450:h=195:color=0x3C82B4:t=fill,drawbox=x=55:y=275:w=530:h=32:color=0xAAB4BE:t=fill', '-t', '2',
            '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-f', 'mp4', $source]))->mustRun();
        $originalHash = hash_file('sha256', $source);

        [$owner, $return] = $this->receivedReturn();
        $evidence = app(CustomerReturnEvidenceService::class)->record($return->items()->firstOrFail(), $owner, [
            'request_id' => (string) Str::uuid(), 'upload_id' => (string) Str::uuid(),
            'problem_type' => 'damaged', 'captured_at_device' => '2026-10-26T12:01:21+05:00',
            'capture_timezone' => 'Asia/Karachi',
        ], UploadedFile::fake()->image('synthetic-layout.jpg', 640, 360));
        $service = app(CustomerReturnEvidenceLabelService::class);
        $layout = (new \ReflectionMethod($service, 'layout'))->invoke($service, $evidence, 640, 360);
        file_put_contents(Storage::disk('local')->path($directory.'/clock.txt'),
            '%{pts:gmtime:'.$layout['wallEpoch'].':%d-%m-%Y %a %H\\:%M\\:%S} '.$layout['abbreviation']);
        $filter = (new \ReflectionMethod($service, 'videoFilter'))->invoke($service, $layout, 640, 360, 'clock.txt');
        (new Process([$binary, '-nostdin', '-hide_banner', '-loglevel', 'error',
            '-i', $source, '-vf', $filter, '-map', '0:v:0', '-map', '0:a?',
            '-c:v', 'libx264', '-crf', '18', '-preset', 'medium', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '192k', '-movflags', '+faststart', '-f', 'mp4', $labeled],
            Storage::disk('local')->path($directory)))->mustRun();

        $this->assertGreaterThan(0, filesize($labeled));
        $this->assertSame($originalHash, hash_file('sha256', $source));
        $frame = Storage::disk('local')->path($directory.'/frame.png');
        (new Process([$binary, '-nostdin', '-hide_banner', '-loglevel', 'error',
            '-i', $labeled, '-ss', '0.2', '-frames:v', '1', $frame]))->mustRun();
        $this->assertSame([640, 360], array_slice(getimagesize($frame), 0, 2));
        $timestampFrame = static function (string $seconds) use ($binary, $labeled): string {
            $process = new Process([$binary, '-nostdin', '-hide_banner', '-loglevel', 'error',
                '-i', $labeled, '-ss', $seconds, '-frames:v', '1',
                '-vf', 'crop=640:40:0:0', '-f', 'md5', '-']);
            $process->mustRun();

            return trim($process->getOutput());
        };
        $firstSecond = $timestampFrame('0.2');
        $nextSecond = $timestampFrame('1.2');
        $this->assertMatchesRegularExpression('/^MD5=[a-f0-9]{32}$/', $firstSecond);
        $this->assertMatchesRegularExpression('/^MD5=[a-f0-9]{32}$/', $nextSecond);
        $this->assertNotSame($firstSecond, $nextSecond);
    }

    public function test_portrait_video_label_renders_without_cropping(): void
    {
        $binary = getenv('TPZ_TEST_FFMPEG_BINARY');
        if (! is_string($binary) || ! is_file($binary)) {
            $this->markTestSkipped('Set TPZ_TEST_FFMPEG_BINARY to run the local portrait-video check.');
        }

        [$owner, $return] = $this->receivedReturn();
        $evidence = app(CustomerReturnEvidenceService::class)->record($return->items()->firstOrFail(), $owner, [
            'request_id' => (string) Str::uuid(), 'upload_id' => (string) Str::uuid(),
            'problem_type' => 'damaged', 'captured_at_device' => '2026-10-26T12:01:21+05:00',
            'capture_timezone' => 'Asia/Karachi',
        ], UploadedFile::fake()->image('portrait-layout.jpg', 360, 640));
        $service = app(CustomerReturnEvidenceLabelService::class);
        $layout = (new \ReflectionMethod($service, 'layout'))->invoke($service, $evidence, 360, 640);
        $directory = 'synthetic-portrait-label';
        Storage::disk('local')->makeDirectory($directory);
        $source = Storage::disk('local')->path($directory.'/original.mp4');
        $labeled = Storage::disk('local')->path($directory.'/labeled.mp4');
        file_put_contents(Storage::disk('local')->path($directory.'/clock.txt'),
            '%{pts:gmtime:'.$layout['wallEpoch'].':%d-%m-%Y %a %H\\:%M\\:%S} '.$layout['abbreviation']);
        (new Process([$binary, '-nostdin', '-hide_banner', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'color=c=0xD6EAF8:s=360x640:r=25:d=2,drawbox=x=45:y=95:w=270:h=410:color=0x2F4257:t=fill,drawbox=x=58:y=110:w=244:h=375:color=0x3C82B4:t=fill,drawbox=x=30:y=505:w=300:h=62:color=0xAAB4BE:t=fill', '-t', '2',
            '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-f', 'mp4', $source]))->mustRun();
        $originalHash = hash_file('sha256', $source);
        $filter = (new \ReflectionMethod($service, 'videoFilter'))->invoke($service, $layout, 360, 640, 'clock.txt');
        (new Process([$binary, '-nostdin', '-hide_banner', '-loglevel', 'error',
            '-i', $source, '-vf', $filter, '-c:v', 'libx264', '-pix_fmt', 'yuv420p',
            '-f', 'mp4', $labeled], Storage::disk('local')->path($directory)))->mustRun();
        $this->assertGreaterThan(0, filesize($labeled));
        $this->assertSame($originalHash, hash_file('sha256', $source));
        $frame = Storage::disk('local')->path($directory.'/frame.png');
        (new Process([$binary, '-nostdin', '-hide_banner', '-loglevel', 'error',
            '-i', $labeled, '-frames:v', '1', $frame]))->mustRun();
        $this->assertSame([360, 640], array_slice(getimagesize($frame), 0, 2));
        $sourceFrame = Storage::disk('local')->path($directory.'/source-frame.png');
        (new Process([$binary, '-nostdin', '-hide_banner', '-loglevel', 'error',
            '-i', $source, '-frames:v', '1', $sourceFrame]))->mustRun();
        $originalImage = imagecreatefrompng($sourceFrame);
        $labelImage = imagecreatefrompng($frame);
        try {
            $originalRed = imagecolorsforindex($originalImage, imagecolorat($originalImage, 350, 5))['red'];
            $labelRed = imagecolorsforindex($labelImage, imagecolorat($labelImage, 350, 5))['red'];
            $this->assertEqualsWithDelta($originalRed * .6, $labelRed, 20);
        } finally {
            imagedestroy($originalImage);
            imagedestroy($labelImage);
        }
        $this->assertLessThan(640 * .42, $layout['footerHeight']);
    }

    public function test_long_references_wrap_within_portrait_and_landscape_frames(): void
    {
        [$owner, $return] = $this->receivedReturn();
        $return->update(['reference' => 'RTN-2026-'.str_repeat('1234567890', 5)]);
        $return->order->update(['reference' => 'SO-2026-'.str_repeat('9876543210', 5)]);
        $evidence = app(CustomerReturnEvidenceService::class)->record($return->items()->firstOrFail(), $owner, [
            'request_id' => (string) Str::uuid(), 'upload_id' => (string) Str::uuid(),
            'problem_type' => 'damaged', 'captured_at_device' => '2026-10-26T12:01:21+05:00',
            'capture_timezone' => 'Asia/Karachi',
        ], UploadedFile::fake()->image('long-reference.jpg', 600, 900));
        $service = app(CustomerReturnEvidenceLabelService::class);
        foreach ([[600, 900], [900, 600]] as [$width, $height]) {
            $layout = (new \ReflectionMethod($service, 'layout'))->invoke($service, $evidence, $width, $height);
            $this->assertLessThan($height * .42, $layout['footerHeight']);
            foreach ($layout['lines'] as $line) {
                $box = imagettfbbox($layout['fontSize'], 0, $layout['font'], $line);
                $this->assertLessThanOrEqual($width - 2 * $layout['margin'],
                    max($box[2], $box[4]) - min($box[0], $box[6]));
            }
        }
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

    public function test_resumable_sixteen_megabyte_video_checks_chunks_and_finalizes_once_without_inventory_posting(): void
    {
        [$owner, $return, $inventory] = $this->receivedReturn();
        $item = $return->items()->firstOrFail();
        $header = pack('N', 24).'ftypisom'.pack('N', 0).'isommp42';
        $mvhd = pack('N', 28).'mvhd'.pack('N', 0).pack('N', 0).pack('N', 0).pack('N', 1000).pack('N', 300000);
        $movie = $header.pack('N', strlen($mvhd) + 8).'moov'.$mvhd;
        $movie .= str_repeat('x', 17301504 - strlen($movie)); // 16.5 MiB, four transport parts.
        $root = '/api/mobile/v1/workspace/qc/return-evidence';
        $this->withToken($owner->createToken('Resumable return video')->plainTextToken);
        $input = ['upload_id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(),
            'original_name' => 'part-one.mp4', 'mime_type' => 'video/mp4', 'byte_size' => strlen($movie),
            'source_md5' => md5($movie), 'duration_seconds' => 300, 'problem_type' => 'damaged',
            'captured_at_device' => '2026-10-24T12:34:56+05:00', 'capture_timezone' => 'Asia/Karachi'];
        $create = $root.'/'.$return->id.'/items/'.$item->id.'/video-uploads';
        $start = $this->postJson($create, $input)->assertOk()->assertJsonPath('data.total_chunks', 4);
        $sessionId = $start->json('data.session_id');
        $this->postJson($create, $input)->assertOk()->assertJsonPath('data.session_id', $sessionId);
        $this->assertDatabaseCount('customer_return_evidence', 0);
        $part = $root.'/video-uploads/'.$sessionId.'/chunks/';
        $first = substr($movie, 0, CustomerReturnVideoUploadService::CHUNK_BYTES);
        $this->post($part.'0', ['checksum' => str_repeat('0', 32), 'chunk' => UploadedFile::fake()->createWithContent('part', $first)])->assertUnprocessable();
        foreach (range(0, 3) as $index) {
            $bytes = substr($movie, $index * CustomerReturnVideoUploadService::CHUNK_BYTES, CustomerReturnVideoUploadService::CHUNK_BYTES);
            $payload = ['checksum' => md5($bytes), 'chunk' => UploadedFile::fake()->createWithContent('part', $bytes)];
            $this->post($part.$index, $payload)->assertOk()->assertJsonPath('data.received_chunks.'.$index, $index);
            if ($index === 0) {
                $this->post($part.$index, ['checksum' => md5($bytes), 'chunk' => UploadedFile::fake()->createWithContent('part', $bytes)])->assertOk();
                Storage::disk('local')->delete('customer-return-evidence/upload-sessions/'.$sessionId.'/0.part');
                $this->post($part.$index, ['checksum' => md5($bytes), 'chunk' => UploadedFile::fake()->createWithContent('part', $bytes)])->assertOk();
            }
        }
        $this->getJson($root.'/video-uploads/'.$sessionId)->assertOk()->assertJsonPath('data.received_chunks.3', 3);
        Queue::fake();
        $lock = Cache::lock('return-video-upload:'.$sessionId, 180);
        $this->assertTrue($lock->get());
        try {
            app(CustomerReturnVideoUploadService::class)->requestFinalization(CustomerReturnVideoUploadSession::query()->findOrFail($sessionId), $owner, true);
            $this->fail('A second finalizer must wait for the session lock.');
        } catch (LockTimeoutException) {
            $this->assertDatabaseCount('customer_return_evidence', 0);
        } finally {
            $lock->release();
        }
        $this->postJson($root.'/video-uploads/'.$sessionId.'/finalize')->assertStatus(426);
        Queue::assertNothingPushed();
        $this->postJson($root.'/video-uploads/'.$sessionId.'/finalize', ['async' => true])->assertStatus(202)
            ->assertJsonPath('data.status', 'processing');
        $this->postJson($root.'/video-uploads/'.$sessionId.'/finalize', ['async' => true])->assertStatus(202);
        Queue::assertPushed(FinalizeCustomerReturnVideo::class, 1);
        Queue::assertPushed(FinalizeCustomerReturnVideo::class,
            fn (FinalizeCustomerReturnVideo $job) => $job->connection === 'return_evidence_uploads'
                && config('queue.connections.return_evidence_uploads.retry_after') > $job->timeout);
        $this->assertDatabaseCount('customer_return_evidence', 0);
        app(FinalizeCustomerReturnVideo::class, ['sessionId' => $sessionId])->handle(app(CustomerReturnVideoUploadService::class));
        $firstFinal = $this->postJson($root.'/video-uploads/'.$sessionId.'/finalize', ['async' => true])->assertOk();
        $this->postJson($root.'/video-uploads/'.$sessionId.'/finalize', ['async' => true])->assertOk()
            ->assertJsonPath('data.evidence_id', $firstFinal->json('data.evidence_id'));
        $evidence = CustomerReturnEvidence::query()->sole();
        $this->assertSame(hash('sha256', $movie), $evidence->sha256);
        $this->assertSame($movie, Storage::disk('local')->get($evidence->original_path));
        $this->assertDatabaseCount('customer_return_findings', 1);
        $this->assertSame(1, $inventory->refresh()->qc_pending_quantity);
    }

    public function test_video_session_rejects_other_employees_expiry_and_invalid_boundaries(): void
    {
        [$owner, $return] = $this->receivedReturn();
        $item = $return->items()->firstOrFail();
        $base = '/api/mobile/v1/workspace/qc/return-evidence/'.$return->id.'/items/'.$item->id.'/video-uploads';
        $input = ['upload_id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(),
            'original_name' => 'test.mp4', 'mime_type' => 'video/mp4', 'byte_size' => 200 * 1024 * 1024,
            'source_md5' => str_repeat('a', 32), 'duration_seconds' => 300, 'problem_type' => 'wrong_item',
            'captured_at_device' => '2026-10-24T12:34:56+05:00', 'capture_timezone' => 'Asia/Karachi'];
        $this->withToken($owner->createToken('Video boundary')->plainTextToken);
        $session = $this->postJson($base, $input)->assertOk()->assertJsonPath('data.total_chunks', 40)->json('data.session_id');
        $this->postJson($base, [...$input, 'upload_id' => (string) Str::uuid(), 'byte_size' => 100 * 1024 * 1024])
            ->assertOk()->assertJsonPath('data.total_chunks', 20);
        $this->postJson($base, [...$input, 'upload_id' => (string) Str::uuid(), 'byte_size' => 200 * 1024 * 1024 + 1])->assertUnprocessable();
        $this->postJson($base, [...$input, 'upload_id' => (string) Str::uuid(), 'duration_seconds' => 301])->assertUnprocessable();
        $this->postJson('/api/mobile/v1/workspace/qc/return-evidence/video-uploads/'.$session.'/finalize', ['async' => true])->assertUnprocessable();
        $other = User::factory()->create(['email' => 'other-return-owner@techpointzone.com']);
        Employee::factory()->for($other)->role(EmployeeRole::Owner)->create(['email' => $other->email]);
        $this->app['auth']->forgetGuards();
        $this->withToken($other->createToken('Other return employee')->plainTextToken)
            ->getJson('/api/mobile/v1/workspace/qc/return-evidence/video-uploads/'.$session)->assertNotFound();
        $this->postJson('/api/mobile/v1/workspace/qc/return-evidence/video-uploads/'.$session.'/finalize', ['async' => true])->assertNotFound();
        $this->postJson($base, $input)->assertNotFound();
        CustomerReturnVideoUploadSession::query()->findOrFail($session)->update(['expires_at' => now()->subMinute()]);
        $this->app['auth']->forgetGuards();
        $this->withToken($owner->createToken('Expired video')->plainTextToken)
            ->getJson('/api/mobile/v1/workspace/qc/return-evidence/video-uploads/'.$session)->assertStatus(410);
        $this->assertSame(1, app(CustomerReturnVideoUploadService::class)->pruneExpired());
        $this->assertDatabaseMissing('customer_return_video_upload_sessions', ['id' => $session]);
        $this->assertDatabaseCount('customer_return_evidence', 0);
    }

    public function test_failed_worker_can_resume_the_same_video_without_duplicate_evidence(): void
    {
        [$owner, $return] = $this->receivedReturn();
        $item = $return->items()->firstOrFail();
        $mvhd = pack('N', 28).'mvhd'.pack('N', 0).pack('N', 0).pack('N', 0).pack('N', 1000).pack('N', 24000);
        $movie = pack('N', 24).'ftypisom'.pack('N', 0).'isommp42'.pack('N', strlen($mvhd) + 8).'moov'.$mvhd;
        $root = '/api/mobile/v1/workspace/qc/return-evidence';
        $create = $root.'/'.$return->id.'/items/'.$item->id.'/video-uploads';
        $input = ['upload_id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(),
            'original_name' => 'recording.mp4', 'mime_type' => 'video/mp4', 'byte_size' => strlen($movie),
            'source_md5' => md5($movie), 'duration_seconds' => 24, 'problem_type' => 'damaged',
            'captured_at_device' => '2026-10-24T12:34:56+05:00', 'capture_timezone' => 'Asia/Karachi'];
        $this->withToken($owner->createToken('Retry return video')->plainTextToken);
        $sessionId = $this->postJson($create, $input)->assertOk()->json('data.session_id');
        $this->post($root.'/video-uploads/'.$sessionId.'/chunks/0', [
            'checksum' => md5($movie), 'chunk' => UploadedFile::fake()->createWithContent('part', $movie),
        ])->assertOk();
        Queue::fake();
        $this->postJson($root.'/video-uploads/'.$sessionId.'/finalize', ['async' => true])->assertStatus(202);
        app(FinalizeCustomerReturnVideo::class, ['sessionId' => $sessionId])->failed(new \ErrorException('Permission denied'));
        $this->getJson($root.'/video-uploads/'.$sessionId)->assertOk()->assertJsonPath('data.status', 'failed');
        $this->assertDatabaseCount('customer_return_evidence', 0);
        $path = 'customer-return-evidence/upload-sessions/'.$sessionId.'/0.part';
        $this->assertTrue(Storage::disk('local')->exists($path));

        $this->postJson($create, $input)->assertOk()->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.received_chunks.0', 0);
        $this->postJson($root.'/video-uploads/'.$sessionId.'/finalize', ['async' => true])->assertStatus(202);
        app(FinalizeCustomerReturnVideo::class, ['sessionId' => $sessionId])->handle(app(CustomerReturnVideoUploadService::class));
        $evidence = CustomerReturnEvidence::query()->sole();
        $this->assertSame(hash('sha256', $movie), $evidence->sha256);
        $this->assertSame($movie, Storage::disk('local')->get($evidence->original_path));
        $this->postJson($create, $input)->assertOk()->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.evidence_id', $evidence->id);
        $this->assertDatabaseCount('customer_return_evidence', 1);
    }

    public function test_failed_session_requires_missing_or_corrupted_chunks_to_be_resent(): void
    {
        [$owner, $return] = $this->receivedReturn();
        $item = $return->items()->firstOrFail();
        $movie = pack('N', 24).'ftypisom'.pack('N', 0).'isommp42';
        $root = '/api/mobile/v1/workspace/qc/return-evidence';
        $create = $root.'/'.$return->id.'/items/'.$item->id.'/video-uploads';
        $input = ['upload_id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(),
            'original_name' => 'retry.mp4', 'mime_type' => 'video/mp4', 'byte_size' => strlen($movie),
            'source_md5' => md5($movie), 'duration_seconds' => 3, 'problem_type' => 'damaged',
            'captured_at_device' => '2026-10-24T12:34:56+05:00', 'capture_timezone' => 'Asia/Karachi'];
        $this->withToken($owner->createToken('Repair return video')->plainTextToken);
        $sessionId = $this->postJson($create, $input)->assertOk()->json('data.session_id');
        $part = $root.'/video-uploads/'.$sessionId.'/chunks/0';
        $send = fn () => $this->post($part, [
            'checksum' => md5($movie), 'chunk' => UploadedFile::fake()->createWithContent('part', $movie),
        ])->assertOk();
        $send();
        Queue::fake();
        $finalize = $root.'/video-uploads/'.$sessionId.'/finalize';
        $this->postJson($finalize, ['async' => true])->assertStatus(202);
        $job = app(FinalizeCustomerReturnVideo::class, ['sessionId' => $sessionId]);
        $job->failed(new \ErrorException('Permission denied'));
        $path = 'customer-return-evidence/upload-sessions/'.$sessionId.'/0.part';
        Storage::disk('local')->delete($path);
        $this->postJson($create, $input)->assertOk()->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.received_chunks', []);

        $send();
        $this->postJson($finalize, ['async' => true])->assertStatus(202);
        $job->failed(new \ErrorException('Permission denied'));
        Storage::disk('local')->put($path, str_repeat('x', strlen($movie)));
        $this->postJson($create, $input)->assertOk()->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.received_chunks', []);
        $send();
        $this->getJson($root.'/video-uploads/'.$sessionId)->assertOk()
            ->assertJsonPath('data.received_chunks.0', 0);
        $this->assertDatabaseCount('customer_return_evidence', 0);
    }

    public function test_multiple_resumable_exception_videos_remain_separate_and_bad_finalization_keeps_chunks(): void
    {
        [$owner, $return, $inventory] = $this->receivedReturn();
        $item = $return->items()->firstOrFail();
        $movements = StockMovement::query()->count();
        $this->withToken($owner->createToken('Multiple return videos')->plainTextToken);
        $root = '/api/mobile/v1/workspace/qc/return-evidence';
        $mvhd = pack('N', 28).'mvhd'.pack('N', 0).pack('N', 0).pack('N', 0).pack('N', 1000).pack('N', 7000);
        $movie = pack('N', 24).'ftypisom'.pack('N', 0).'isommp42'.pack('N', strlen($mvhd) + 8).'moov'.$mvhd;
        foreach (['wrong_item', 'empty_package', 'damaged'] as $number => $problem) {
            $input = ['upload_id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(),
                'original_name' => 'exception-'.$number.'.mp4', 'mime_type' => 'video/mp4', 'byte_size' => strlen($movie),
                'source_md5' => $number === 0 ? str_repeat('0', 32) : md5($movie),
                'duration_seconds' => 7, 'problem_type' => $problem,
                'captured_at_device' => '2026-10-24T12:34:56+05:00', 'capture_timezone' => 'Asia/Karachi'];
            $session = $this->postJson($root.'/'.$return->id.'/items/'.$item->id.'/video-uploads', $input)
                ->assertOk()->json('data.session_id');
            $this->post($root.'/video-uploads/'.$session.'/chunks/0', [
                'checksum' => md5($movie), 'chunk' => UploadedFile::fake()->createWithContent('part', $movie),
            ])->assertOk();
            Queue::fake();
            $this->postJson($root.'/video-uploads/'.$session.'/finalize', ['async' => true])->assertStatus(202);
            app(FinalizeCustomerReturnVideo::class, ['sessionId' => $session])->handle(app(CustomerReturnVideoUploadService::class));
            if ($number === 0) {
                $this->getJson($root.'/video-uploads/'.$session)->assertOk()->assertJsonPath('data.status', 'failed');
                $this->assertDatabaseCount('customer_return_evidence', 0);
                $this->assertTrue(Storage::disk('local')->exists('customer-return-evidence/upload-sessions/'.$session.'/0.part'));
            } else {
                $this->postJson($root.'/video-uploads/'.$session.'/finalize', ['async' => true])->assertOk();
            }
        }
        $this->assertDatabaseCount('customer_return_evidence', 2);
        $this->getJson($root.'/'.$return->id)->assertOk()
            ->assertJsonPath('data.items.0.findings.0.evidence.0.video_part', 1)
            ->assertJsonPath('data.items.0.findings.1.evidence.0.video_part', 2);
        $this->assertSame(1, $inventory->refresh()->qc_pending_quantity);
        $this->assertSame($movements, StockMovement::query()->count());
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

    private function syntheticLaptopPhoto(int $width, int $height): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($image, 230, 240, 250);
        $body = imagecolorallocate($image, 47, 66, 87);
        $display = imagecolorallocate($image, 60, 130, 180);
        $keyboard = imagecolorallocate($image, 170, 180, 190);
        imagefill($image, 0, 0, $background);
        imagefilledrectangle($image, (int) ($width * .13), (int) ($height * .15),
            (int) ($width * .87), (int) ($height * .74), $body);
        imagefilledrectangle($image, (int) ($width * .17), (int) ($height * .19),
            (int) ($width * .83), (int) ($height * .68), $display);
        imagefilledrectangle($image, (int) ($width * .08), (int) ($height * .74),
            (int) ($width * .92), (int) ($height * .81), $keyboard);
        ob_start();
        imagejpeg($image, null, 95);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return UploadedFile::fake()->createWithContent("synthetic-laptop-$width.jpg", $bytes);
    }
}
