<?php

namespace Tests\Feature\ServiceCases;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\WarrantyRepairSource;
use App\Enums\WarrantyRepairStatus;
use App\Filament\Pages\Service\RepairManagement;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\Product;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentProduct;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarrantyRepair;
use App\Services\ServiceCases\RepairManagementReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class RepairManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_unified_screen_shows_internal_and_customer_service_separately_with_tabs(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        $internal = $this->repair($owner, $product, $warehouse, WarrantyRepairSource::DamagedItem, WarrantyRepairStatus::PendingClassification);
        $customer = $this->repair($owner, $product, $warehouse, WarrantyRepairSource::Manual, WarrantyRepairStatus::InRepair);
        $service = app(RepairManagementReadService::class);

        $this->assertEqualsCanonicalizing([$internal->id, $customer->id], $service->paginate($owner, ['tab' => 'all'])->pluck('id')->all());
        $this->assertSame([$internal->id], $service->paginate($owner, ['tab' => 'classification'])->pluck('id')->all());
        $this->assertSame([$customer->id], $service->paginate($owner, ['tab' => 'technician'])->pluck('id')->all());
        $this->assertSame(1, $service->summary($owner)['classification']);

        Livewire::actingAs($owner)->test(RepairManagement::class)
            ->assertSee($internal->reference)
            ->assertSee($customer->reference)
            ->assertSee('Company-Owned')
            ->assertSee('Customer / Warranty')
            ->call('setTab', 'classification')
            ->assertSee($internal->reference)
            ->assertDontSee($customer->reference);
    }

    public function test_staff_only_sees_responsibility_scoped_repairs_and_cannot_classify(): void
    {
        [$owner, $allowedProduct, $warehouse] = $this->foundation();
        $otherProduct = Product::factory()->create();
        $allowed = $this->repair($owner, $allowedProduct, $warehouse, WarrantyRepairSource::DamagedItem, WarrantyRepairStatus::PendingClassification);
        $denied = $this->repair($owner, $otherProduct, $warehouse, WarrantyRepairSource::Manual, WarrantyRepairStatus::InRepair);
        $staff = User::factory()->create();
        $employee = Employee::factory()->for($staff)->role(EmployeeRole::Staff)->create(['email' => $staff->email]);
        EmployeePermissionOverride::query()->create([
            'employee_id' => $employee->id, 'permission_key' => 'warranty_repair.view',
            'effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $owner->id,
            'reason' => 'Repair scope test',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($employee->id);
        $assignment = ResponsibilityAssignment::factory()->create(['employee_id' => $employee->id, 'assigned_by_user_id' => $owner->id]);
        ResponsibilityAssignmentProduct::query()->create(['assignment_id' => $assignment->id, 'product_id' => $allowedProduct->id]);

        $this->assertSame([$allowed->id], app(RepairManagementReadService::class)->paginate($staff, ['tab' => 'all'])->pluck('id')->all());
        Livewire::actingAs($staff)->test(RepairManagement::class)
            ->assertSee($allowed->reference)
            ->assertDontSee($denied->reference);
    }

    /** @return array{User, Product, Warehouse} */
    private function foundation(): array
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);

        return [$owner, Product::factory()->create(), Warehouse::factory()->create(['status' => true])];
    }

    private function repair(User $owner, Product $product, Warehouse $warehouse, WarrantyRepairSource $source, WarrantyRepairStatus $status): WarrantyRepair
    {
        return WarrantyRepair::query()->create([
            'reference' => 'WR-2026-'.Str::upper(Str::random(8)), 'product_id' => $product->id,
            'warehouse_id' => $warehouse->id, 'quantity' => 1,
            'source' => $source, 'issue_description' => 'Test repair', 'received_at' => now(),
            'status' => $status, 'idempotency_key' => (string) Str::uuid(), 'created_by_user_id' => $owner->id,
        ]);
    }
}
