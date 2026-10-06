<?php

namespace Tests\Feature\Qc;

use App\Enums\EmployeeRole;
use App\Filament\Resources\QcInspections\Pages\CreateQcInspection;
use App\Filament\Resources\QcInspections\Pages\EditQcInspection;
use App\Filament\Resources\QcInspections\Pages\ListQcInspections;
use App\Models\Employee;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Qc\QcInspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class QcWorkflowBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function foundation(): array
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $email = 'qc-boundary-'.str()->random(12).'@techpointzone.com';
        $owner->update(['email' => $email]);
        $owner->employee->update(['email' => $email]);
        $product = Product::factory()->create(['touch_screen' => false]);
        $warehouse = Warehouse::factory()->create();

        return [$owner, $product, $warehouse];
    }

    private function data($product, $warehouse, string $serial = 'QC-BOUNDARY-001'): array
    {
        return ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'serial' => $serial, 'features' => [], 'idempotency_key' => (string) str()->uuid()];
    }

    public function test_create_another_uses_fresh_idempotency_key_without_duplicate_devices(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        $component = Livewire::actingAs($owner)->test(CreateQcInspection::class)->fillForm($this->data($product, $warehouse));
        $firstKey = $component->instance()->startKey;
        $component->call('create', true)->assertHasNoFormErrors();
        $this->assertNotSame($firstKey, $component->instance()->startKey);
        $component->fillForm($this->data($product, $warehouse, 'QC-BOUNDARY-002'))->call('create')->assertHasNoFormErrors();
        $this->assertDatabaseCount('qc_devices', 2);
        $this->assertDatabaseCount('qc_inspections', 2);
    }

    public function test_known_touchscreen_is_applicable_and_na_cannot_skip_it(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        $product->update(['touch_screen' => true]);
        $inspection = app(QcInspectionService::class)->start($this->data($product, $warehouse), $owner);
        $check = $inspection->checks()->where('check_key', 'touchscreen')->sole();
        $this->assertTrue($check->applicable);
        $this->assertFalse($check->definition['allows_na']);
        $this->expectException(ValidationException::class);
        app(QcInspectionService::class)->update($inspection, ['checks' => ['touchscreen' => ['result' => 'na']]], $owner);
    }

    public function test_pass_all_does_not_silently_overwrite_failed_check(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        $service = app(QcInspectionService::class);
        $inspection = $service->start($this->data($product, $warehouse), $owner);
        $service->update($inspection, ['checks' => ['wifi' => ['result' => 'fail', 'notes' => 'Fails connection test']]], $owner);
        $service->passGroup($inspection, 'Connectivity', $owner);
        $this->assertSame('fail', $inspection->checks()->where('check_key', 'wifi')->sole()->result);
        $this->assertSame('rework_required', $inspection->fresh()->status->value);
    }

    public function test_failed_complete_livewire_action_rolls_back_save_and_keeps_entered_data(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        $inspection = app(QcInspectionService::class)->start($this->data($product, $warehouse), $owner);
        $component = Livewire::actingAs($owner)->test(EditQcInspection::class, ['record' => $inspection->id])
            ->set('data.grade', 'A')->set('data.final_configuration.cpu', 'Intel i5')
            ->set('data.final_configuration.ram_mb', 16384)->set('data.final_configuration.storage_gb', 512)->set('data.final_configuration.os', 'Windows 11')
            ->callAction('completeQc')->assertHasErrors(['data.evidence.serial']);
        $this->assertSame('A', $component->instance()->data['grade']);
        $this->assertNull($inspection->fresh()->grade);
        $this->assertSame('pending', $inspection->fresh()->status->value);
        $this->assertDatabaseCount('qc_certificates', 0);
    }

    public function test_label_http_errors_are_readable_not_silent_redirects(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        $inspection = app(QcInspectionService::class)->start($this->data($product, $warehouse), $owner);
        $this->actingAs($owner)->get(route('qc.labels', ['ids' => [$inspection->id]]))->assertStatus(422)->assertSee('QC document was not printed')->assertDontSee('SQLSTATE');
    }

    public function test_pending_jobs_are_not_selectable_for_verified_labels(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        $inspection = app(QcInspectionService::class)->start($this->data($product, $warehouse), $owner);
        $component = Livewire::actingAs($owner)->test(ListQcInspections::class)->assertCanSeeTableRecords([$inspection]);
        $record = $component->instance()->getTableRecords()->first();
        $this->assertFalse($component->instance()->getTable()->isRecordSelectable($record));
        $this->assertSame(50, $component->instance()->getTable()->getMaxSelectableRecords());
    }
}
