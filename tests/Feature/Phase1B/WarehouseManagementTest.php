<?php

namespace Tests\Feature\Phase1B;

use App\Actions\Warehouses\CreateWarehouse;
use App\Actions\Warehouses\SetWarehouseStatus;
use App\Actions\Warehouses\UpdateWarehouse;
use App\DTOs\Warehouses\ChangeWarehouseStatusData;
use App\DTOs\Warehouses\CreateWarehouseData;
use App\DTOs\Warehouses\UpdateWarehouseData;
use App\Enums\EmployeeRole;
use App\Exceptions\LastUsableWarehouseException;
use App\Exceptions\MainWarehouseImmutableException;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WarehouseManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_code_is_trimmed_uppercased_validated_and_unique(): void
    {
        $owner = $this->owner();
        $warehouse = app(CreateWarehouse::class)->handle(new CreateWarehouseData('Second', '  wh_test-1  '), $owner);

        $this->assertSame('WH_TEST-1', $warehouse->code);
        $this->assertTrue($warehouse->status);
        $this->assertFalse($warehouse->is_default);

        $this->expectException(ValidationException::class);
        app(CreateWarehouse::class)->handle(new CreateWarehouseData('Invalid', 'not valid!'), $owner);
    }

    public function test_main_name_code_and_status_are_immutable_and_rejections_are_logged(): void
    {
        $owner = $this->owner();
        $main = Warehouse::query()->where('code', 'MAIN')->firstOrFail();

        try {
            app(UpdateWarehouse::class)->handle($main, new UpdateWarehouseData('Renamed', 'MAIN'), $owner);
            $this->fail('Main identity change should fail.');
        } catch (MainWarehouseImmutableException) {
            $this->assertTrue(ActivityLog::query()->where('event', 'warehouse.main_identity_change_rejected')->exists());
        }

        $this->expectException(MainWarehouseImmutableException::class);
        app(SetWarehouseStatus::class)->handle($main, new ChangeWarehouseStatusData(false, 'Attempted closure'), $owner);
    }

    public function test_warehouse_status_change_requires_reason(): void
    {
        $owner = $this->owner();
        $warehouse = Warehouse::factory()->create();

        $this->expectException(ValidationException::class);
        app(SetWarehouseStatus::class)->handle($warehouse, new ChangeWarehouseStatusData(false, ''), $owner);
    }

    public function test_only_usable_non_main_warehouse_cannot_be_deactivated(): void
    {
        $owner = $this->owner();
        Warehouse::query()->where('code', 'MAIN')->update(['status' => false, 'is_default' => false]);
        $warehouse = Warehouse::factory()->create();

        $this->expectException(LastUsableWarehouseException::class);
        app(SetWarehouseStatus::class)->handle(
            $warehouse,
            new ChangeWarehouseStatusData(false, 'Would leave no usable Warehouse'),
            $owner,
        );
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role(EmployeeRole::Owner)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
