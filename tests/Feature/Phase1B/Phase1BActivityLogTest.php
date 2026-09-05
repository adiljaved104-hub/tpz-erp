<?php

namespace Tests\Feature\Phase1B;

use App\Actions\Suppliers\CreateSupplier;
use App\Actions\Suppliers\UpdateSupplier;
use App\Actions\Warehouses\CreateWarehouse;
use App\DTOs\Suppliers\CreateSupplierData;
use App\DTOs\Suppliers\UpdateSupplierData;
use App\DTOs\Warehouses\CreateWarehouseData;
use App\Enums\EmployeeRole;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase1BActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_and_update_events_contain_only_scoped_metadata(): void
    {
        $owner = $this->owner();
        $supplier = app(CreateSupplier::class)->handle(new CreateSupplierData('Initial'), $owner);
        app(UpdateSupplier::class)->handle($supplier, new UpdateSupplierData('Updated'), $owner);
        app(CreateWarehouse::class)->handle(new CreateWarehouseData('Additional', 'extra-1'), $owner);

        $this->assertSame([
            'supplier.created',
            'supplier.updated',
            'warehouse.created',
        ], ActivityLog::query()->orderBy('id')->pluck('event')->all());

        $updated = ActivityLog::query()->where('event', 'supplier.updated')->firstOrFail();
        $this->assertSame(['name'], $updated->properties['changed_fields']);
        $this->assertArrayNotHasKey('record', $updated->properties);
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role(EmployeeRole::Owner)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
