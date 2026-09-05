<?php

namespace Tests\Feature\Phase1B;

use App\Actions\Suppliers\CreateSupplier;
use App\Actions\Suppliers\SetSupplierStatus;
use App\DTOs\Suppliers\ChangeSupplierStatusData;
use App\DTOs\Suppliers\CreateSupplierData;
use App\Enums\EmployeeRole;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\Supplier;
use App\Models\User;
use App\Services\SupplierDuplicateWarningService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SupplierManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_creates_active_supplier_with_nullable_fields_and_activity_log(): void
    {
        $owner = $this->userWithRole(EmployeeRole::Owner);

        $supplier = app(CreateSupplier::class)->handle(new CreateSupplierData('  Example Supplier  '), $owner);

        $this->assertSame('Example Supplier', $supplier->name);
        $this->assertTrue($supplier->status);
        $this->assertNull($supplier->email);
        $this->assertTrue(ActivityLog::query()->where('event', 'supplier.created')->where('subject_id', $supplier->id)->exists());
    }

    public function test_duplicate_supplier_fields_warn_but_do_not_block_creation(): void
    {
        $owner = $this->userWithRole(EmployeeRole::Owner);
        $first = app(CreateSupplier::class)->handle(new CreateSupplierData('Duplicate Co', email: 'same@example.com'), $owner);
        $second = app(CreateSupplier::class)->handle(new CreateSupplierData('Duplicate Co', email: 'same@example.com'), $owner);

        $warnings = app(SupplierDuplicateWarningService::class)->candidates('Duplicate Co', 'same@example.com', null, null, $second);

        $this->assertTrue($warnings->contains($first));
        $this->assertDatabaseCount('suppliers', 2);
    }

    public function test_status_change_requires_reason_and_keeps_inactive_supplier_visible(): void
    {
        $owner = $this->userWithRole(EmployeeRole::Owner);
        $supplier = Supplier::factory()->create();

        $supplier = app(SetSupplierStatus::class)->handle($supplier, new ChangeSupplierStatusData(false, 'No longer used operationally'), $owner);

        $this->assertFalse($supplier->status);
        $this->assertSame(1, Supplier::query()->whereKey($supplier)->count());
        $this->assertSame(0, Supplier::query()->active()->whereKey($supplier)->count());
        $this->assertSame('No longer used operationally', ActivityLog::query()->where('event', 'supplier.status_changed')->firstOrFail()->properties['reason']);

        $this->expectException(ValidationException::class);
        app(SetSupplierStatus::class)->handle($supplier, new ChangeSupplierStatusData(true, '   '), $owner);
    }

    public function test_manager_cannot_create_or_change_supplier(): void
    {
        $manager = $this->userWithRole(EmployeeRole::Manager);

        $this->expectException(AuthorizationException::class);
        app(CreateSupplier::class)->handle(new CreateSupplierData('Denied'), $manager);
    }

    private function userWithRole(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
