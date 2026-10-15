<?php

namespace Tests\Feature\Qc;

use App\Actions\Employees\ChangeEmployeeRole;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Enums\InventoryPermission;
use App\Enums\OfficeFinancePermission;
use App\Enums\OrderPermission;
use App\Enums\ProductPermission;
use App\Enums\QcPermission;
use App\Filament\Pages\Administration\AccessControl;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\QcInspections\QcInspectionResource;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\QcInspection;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\DatabaseEmployeePermissionOverrideResolver;
use App\Services\Authorization\EmployeePermissionCatalog;
use App\Services\Authorization\EmployeePermissionOverrideService;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\ProductAuthorization;
use App\Services\Authorization\QcAuthorization;
use App\Services\Mobile\MobileManifest;
use App\Services\Qc\QcEvidenceService;
use App\Services\Qc\QcInspectionService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class TechnicianRoleSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_technician_role_is_available_and_employee_form_defaults_to_staff(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $this->assertSame('Technician', EmployeeRole::Technician->getLabel());
        $this->assertSame(EmployeeRole::Technician, EmployeeRole::from('technician'));

        $component = Livewire::actingAs($owner->user)->test(CreateEmployee::class)
            ->assertSee('Technician');

        $this->assertSame(EmployeeRole::Staff->value, data_get($component->instance()->data, 'role'));
    }

    public function test_technician_defaults_are_limited_to_the_five_approved_qc_permissions(): void
    {
        $technician = $this->employee(EmployeeRole::Technician);
        $authorization = app(QcAuthorization::class);
        $allowed = [QcPermission::View, QcPermission::Start, QcPermission::Update, QcPermission::Complete, QcPermission::ViewCustomerEvidence];

        foreach (QcPermission::cases() as $permission) {
            $this->assertSame(in_array($permission, $allowed, true), $authorization->roleDefault($technician->user, $permission), $permission->value);
            $this->assertSame(in_array($permission, $allowed, true), $authorization->allows($technician->user, $permission), $permission->value);
        }

        $resolver = app(DatabaseEmployeePermissionOverrideResolver::class);
        $catalog = app(EmployeePermissionCatalog::class);
        foreach (collect($catalog->groups())->flatten(1)->pluck('key')->unique() as $key) {
            if (! in_array($key, array_map(fn (QcPermission $permission) => $permission->value, $allowed), true)) {
                $this->assertFalse($resolver->decision($technician->user, $key), $key);
            }
        }
    }

    public function test_stale_non_qc_and_restricted_qc_allows_are_hard_denied(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $technician = $this->employee(EmployeeRole::Technician);
        foreach ([
            ProductPermission::View->value,
            OrderPermission::View->value,
            InventoryPermission::View->value,
            HrPermission::AttendanceViewAll->value,
            OfficeFinancePermission::ViewBalances->value,
            QcPermission::ViewAll->value,
            QcPermission::Reopen->value,
        ] as $key) {
            $this->override($technician, $key, EmployeePermissionEffect::Allow, $owner->user);
        }

        $resolver = app(DatabaseEmployeePermissionOverrideResolver::class);
        foreach ([
            ProductPermission::View->value,
            OrderPermission::View->value,
            InventoryPermission::View->value,
            HrPermission::AttendanceViewAll->value,
            OfficeFinancePermission::ViewBalances->value,
            QcPermission::ViewAll->value,
            QcPermission::Reopen->value,
        ] as $key) {
            $this->assertFalse($resolver->decision($technician->user, $key), $key);
        }

        $this->assertFalse(app(ProductAuthorization::class)->allows($technician->user, ProductPermission::View));
        $this->assertFalse(app(OrderAuthorization::class)->allows($technician->user, OrderPermission::View));
        $this->assertFalse(app(QcAuthorization::class)->allows($technician->user, QcPermission::ViewAll));
    }

    public function test_access_control_rejects_crafted_non_qc_allow_and_marks_it_restricted(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $technician = $this->employee(EmployeeRole::Technician);
        $component = Livewire::actingAs($owner->user)->test(AccessControl::class)
            ->call('selectEmployee', $technician->id)
            ->set('moduleSearch', 'Products')
            ->assertSee('Restricted by Technician role')
            ->call('stagePermission', ProductPermission::View->value, 'allow')
            ->assertHasErrors();

        $this->assertDatabaseMissing('employee_permission_overrides', [
            'employee_id' => $technician->id,
            'permission_key' => ProductPermission::View->value,
            'effect' => 'allow',
        ]);

        $service = app(EmployeePermissionOverrideService::class);
        try {
            $service->change($technician, ProductPermission::View->value, EmployeePermissionEffect::Allow, null, $owner->user);
            $this->fail('A non-QC Technician Allow must be rejected server-side.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('permission', $exception->errors());
        }

        $service->change($technician, QcPermission::Update->value, EmployeePermissionEffect::Deny, null, $owner->user);
        $this->assertFalse(app(QcAuthorization::class)->allows($technician->user, QcPermission::Update));

        $staff = $this->employee(EmployeeRole::Staff);
        try {
            $service->changeMany(
                [$technician->id, $staff->id],
                [ProductPermission::View->value => EmployeePermissionEffect::Allow],
                null,
                $owner->user,
            );
            $this->fail('A bulk Allow must be rejected when one selected employee is a Technician.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('permission', $exception->errors());
        }
        $this->assertDatabaseMissing('employee_permission_overrides', ['permission_key' => ProductPermission::View->value, 'effect' => 'allow']);
    }

    public function test_entering_and_leaving_technician_prunes_all_allow_overrides_with_an_audit_record(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $staff = $this->employee(EmployeeRole::Staff);
        foreach ([ProductPermission::View->value, OrderPermission::View->value, InventoryPermission::View->value, QcPermission::ViewAll->value] as $key) {
            $this->override($staff, $key, EmployeePermissionEffect::Allow, $owner->user);
        }
        $this->override($staff, ProductPermission::Update->value, EmployeePermissionEffect::Deny, $owner->user);

        app(ChangeEmployeeRole::class)->handle($staff, EmployeeRole::Technician, $owner->user);
        $staff->refresh();
        $this->assertSame(EmployeeRole::Technician, $staff->role);
        foreach ([ProductPermission::View->value, OrderPermission::View->value, InventoryPermission::View->value, QcPermission::ViewAll->value] as $key) {
            $this->assertDatabaseMissing('employee_permission_overrides', ['employee_id' => $staff->id, 'permission_key' => $key]);
        }
        $this->assertDatabaseHas('employee_permission_overrides', ['employee_id' => $staff->id, 'permission_key' => ProductPermission::Update->value, 'effect' => 'deny']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'employee_permission.technician_transition_pruned', 'subject_id' => $staff->id]);

        app(ChangeEmployeeRole::class)->handle($staff, EmployeeRole::Staff, $owner->user);
        $this->assertDatabaseMissing('employee_permission_overrides', ['employee_id' => $staff->id, 'permission_key' => ProductPermission::View->value, 'effect' => 'allow']);
        $this->assertNull(app(DatabaseEmployeePermissionOverrideResolver::class)->decision($staff->user, ProductPermission::View->value));
    }

    public function test_technician_can_use_only_own_qc_over_mobile_and_web_and_start_manual_qc_without_inventory_mutation(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $technician = $this->employee(EmployeeRole::Technician);
        $otherTechnician = $this->employee(EmployeeRole::Technician);
        $product = Product::factory()->create(['touch_screen' => false]);
        $warehouse = Warehouse::factory()->create();
        $service = app(QcInspectionService::class);
        $own = $this->startInspection($service, $owner->user, $product, $warehouse, 'TECH-OWN-'.str()->random(8));
        $other = $this->startInspection($service, $owner->user, $product, $warehouse, 'TECH-OTHER-'.str()->random(8));
        $own->update(['technician_user_id' => $technician->user_id]);
        $other->update(['technician_user_id' => $otherTechnician->user_id]);

        $this->assertSame([$own->id], $service->visible($technician->user)->pluck('id')->all());
        $this->assertTrue(app(QcAuthorization::class)->allows($technician->user, QcPermission::Update, $own));
        $this->assertTrue(app(QcAuthorization::class)->allows($technician->user, QcPermission::Complete, $own));
        $this->assertFalse(app(QcAuthorization::class)->allows($technician->user, QcPermission::View, $other));
        $this->assertFalse(app(QcAuthorization::class)->allows($technician->user, QcPermission::Update, $other));

        $manifest = app(MobileManifest::class)->forUser($technician->user);
        $this->assertSame('qc_focused', $manifest['workspace_mode']);
        $this->assertSame(['qc'], array_column($manifest['modules'], 'key'));
        $evidenceCount = $own->evidence()->count();
        try {
            $service->update($own, ['internal_remarks' => 'private note'], $technician->user);
            $this->fail('Technicians must not write internal QC remarks.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        try {
            app(QcEvidenceService::class)->upload($own, UploadedFile::fake()->image('internal-qc.jpg'), 'additional', false, $technician->user);
            $this->fail('Technicians must not upload internal-only QC evidence.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('qc_evidence', $evidenceCount);
        }
        $internalEvidence = app(QcEvidenceService::class)->upload($own, UploadedFile::fake()->image('owner-internal-qc.jpg'), 'additional', false, $owner->user);

        $token = $technician->user->createToken('Technician QC Test')->plainTextToken;
        $manifestResponse = $this->withToken($token)->getJson('/api/mobile/v1/workspace/manifest')->assertOk();
        $this->assertSame('qc_focused', $manifestResponse->json('workspace_mode'), json_encode($manifestResponse->json(), JSON_UNESCAPED_SLASHES));
        $manifestResponse
            ->assertJsonPath('landing_module', 'qc')
            ->assertJsonPath('modules.0.key', 'qc')
            ->assertJsonCount(1, 'modules');
        $this->withToken($token)->getJson('/api/mobile/v1/workspace/products')->assertForbidden();
        $this->withToken($token)->getJson('/api/mobile/v1/workspace/orders')->assertForbidden();
        $this->withToken($token)->getJson('/api/mobile/v1/workspace/inventory')->assertForbidden();
        $this->withToken($token)->getJson('/api/mobile/v1/workspace/qc/inspections?scope=pending')->assertOk()->assertJsonFragment(['inspection_id' => $own->id])->assertJsonMissing(['inspection_id' => $other->id]);
        $this->withToken($token)->getJson('/api/mobile/v1/workspace/qc/inspections/'.$own->id)
            ->assertOk()->assertJsonPath('inspection.can_update', true)->assertJsonPath('inspection.can_upload_evidence', true)->assertJsonPath('inspection.can_complete', true)
            ->assertJsonMissing(['public_id' => $internalEvidence->public_id]);
        $this->withToken($token)->patchJson('/api/mobile/v1/workspace/qc/inspections/'.$own->id, ['internal_remarks' => 'private note'])->assertForbidden();
        $this->withToken($token)->getJson('/api/mobile/v1/workspace/qc/inspections/'.$other->id)->assertNotFound();
        $this->withToken($token)->postJson('/api/mobile/v1/workspace/qc/inspections/'.$own->id.'/begin')->assertOk();
        $this->withToken($token)->patchJson('/api/mobile/v1/workspace/qc/inspections/'.$own->id, ['public_remarks' => 'Inspected by assigned technician'])->assertOk();
        $this->withToken($token)->postJson('/api/mobile/v1/workspace/qc/inspections/'.$own->id.'/evidence', [
            'kind' => 'serial',
            'customer_visible' => true,
            'file' => UploadedFile::fake()->image('own-qc.jpg'),
        ])->assertCreated();
        $this->withToken($token)->postJson('/api/mobile/v1/workspace/qc/inspections/'.$own->id.'/complete')->assertUnprocessable();
        $this->withToken($token)->postJson('/api/mobile/v1/workspace/qc/inspections/'.$other->id.'/begin')->assertForbidden();
        $this->withToken($token)->patchJson('/api/mobile/v1/workspace/qc/inspections/'.$other->id, ['public_remarks' => 'Not mine'])->assertForbidden();
        $this->withToken($token)->postJson('/api/mobile/v1/workspace/qc/inspections/'.$other->id.'/evidence', [
            'kind' => 'serial',
            'customer_visible' => true,
            'file' => UploadedFile::fake()->image('other-qc.jpg'),
        ])->assertForbidden();
        $this->withToken($token)->postJson('/api/mobile/v1/workspace/qc/inspections/'.$other->id.'/complete')->assertForbidden();

        $this->withToken($token)->getJson('/api/mobile/v1/auth/me')->assertOk();
        $deviceId = (string) str()->uuid();
        $this->withToken($token)->postJson('/api/mobile/v1/devices', [
            'device_id' => $deviceId,
            'expo_token' => 'ExpoPushToken[TechnicianDeviceTest123]',
            'platform' => 'android',
        ])->assertOk();
        $this->withToken($token)->deleteJson('/api/mobile/v1/devices', ['device_id' => $deviceId])->assertOk();
        $this->withToken($token)->postJson('/api/mobile/v1/auth/logout')->assertOk();

        $inventoryCount = ProductInventory::query()->count();
        $manualInspection = $service->start([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'serial' => 'TECH-MANUAL-'.str()->random(8),
            'features' => [],
            'idempotency_key' => (string) str()->uuid(),
        ], $technician->user);
        $this->assertSame($technician->user->id, $manualInspection->technician_user_id);
        $this->assertSame('manual_unlinked', $manualInspection->qc_source);
        $this->assertSame($inventoryCount, ProductInventory::query()->count());

        $this->withoutVite();
        $qcUrl = QcInspectionResource::getUrl('index');
        $this->actingAs($technician->user, 'web')->get('/admin')->assertRedirect($qcUrl);
        $this->actingAs($technician->user, 'web')->get($qcUrl)->assertOk();
        $this->actingAs($technician->user, 'web')->get(QcInspectionResource::getUrl('view', ['record' => $own->id]))->assertOk();
        $this->actingAs($technician->user, 'web')->get(QcInspectionResource::getUrl('edit', ['record' => $own->id]))->assertOk();
        $this->actingAs($technician->user, 'web')->get(route('qc.evidence.internal', ['evidence' => $internalEvidence->id, 'original' => 1]))->assertForbidden();
        $this->actingAs($technician->user, 'web')->get(QcInspectionResource::getUrl('edit', ['record' => $other->id]))->assertNotFound();
        $this->actingAs($technician->user, 'web')->get(ProductResource::getUrl('index'))->assertForbidden();
        $this->actingAs($technician->user, 'web')->get(OrderResource::getUrl('index'))->assertForbidden();

        $groups = Filament::getPanel('admin')->buildNavigation();
        $labels = collect($groups)->flatMap(fn ($group) => $group->getItems())->map(fn ($item) => $item->getLabel())->values()->all();
        $this->assertSame(['Quality Control', 'Recorded Renewed Stock Awaiting QC'], $labels);
    }

    public function test_owner_admin_and_staff_manager_qc_behavior_remains_unchanged(): void
    {
        foreach ([EmployeeRole::Owner, EmployeeRole::Admin] as $role) {
            $employee = $this->employee($role);
            $this->assertTrue(app(QcAuthorization::class)->allows($employee->user, QcPermission::ViewAll));
        }

        foreach ([EmployeeRole::Manager, EmployeeRole::Staff] as $role) {
            $employee = $this->employee($role);
            $this->assertFalse(app(QcAuthorization::class)->roleDefault($employee->user, QcPermission::View));
            $this->assertFalse(app(QcAuthorization::class)->allows($employee->user, QcPermission::ViewAll));
        }
    }

    private function employee(EmployeeRole $role): Employee
    {
        $email = str()->uuid().'@techpointzone.com';
        $user = User::factory()->create(['email' => $email]);

        return Employee::factory()->create(['user_id' => $user->id, 'email' => $email, 'role' => $role]);
    }

    private function override(Employee $employee, string $key, EmployeePermissionEffect $effect, ?User $actor = null): EmployeePermissionOverride
    {
        return EmployeePermissionOverride::query()->create([
            'employee_id' => $employee->id,
            'permission_key' => $key,
            'effect' => $effect,
            'granted_by_user_id' => $actor?->id,
            'reason' => 'Technician role security regression fixture',
        ]);
    }

    private function startInspection(QcInspectionService $service, User $owner, Product $product, Warehouse $warehouse, string $serial): QcInspection
    {
        return $service->start([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'serial' => $serial,
            'features' => [],
            'idempotency_key' => (string) str()->uuid(),
        ], $owner);
    }
}
