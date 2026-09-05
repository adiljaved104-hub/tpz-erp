<?php

namespace Tests\Feature\Phase1B;

use App\Actions\Warehouses\SetDefaultWarehouse;
use App\Actions\Warehouses\SetWarehouseStatus;
use App\DTOs\Warehouses\ChangeDefaultWarehouseData;
use App\DTOs\Warehouses\ChangeWarehouseStatusData;
use App\Enums\EmployeeRole;
use App\Exceptions\DefaultWarehouseException;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DefaultWarehouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DefaultWarehouseTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_switch_is_atomic_reasoned_and_leaves_exactly_one_default(): void
    {
        $owner = $this->owner();
        $candidate = Warehouse::factory()->create();

        app(SetDefaultWarehouse::class)->handle($candidate, new ChangeDefaultWarehouseData('Operational handover'), $owner);

        $this->assertSame(1, Warehouse::query()->where('status', true)->where('is_default', true)->count());
        $this->assertTrue($candidate->refresh()->is_default);
        $this->assertFalse(Warehouse::query()->where('code', 'MAIN')->firstOrFail()->is_default);
        $this->assertSame($candidate->id, app(DefaultWarehouseService::class)->operationalDefault()->id);
        $this->assertSame('Operational handover', ActivityLog::query()->where('event', 'warehouse.default_changed')->firstOrFail()->properties['reason']);
    }

    public function test_inactive_warehouse_cannot_become_default_and_attempt_is_logged(): void
    {
        $owner = $this->owner();
        $inactive = Warehouse::factory()->inactive()->create();

        $this->expectException(DefaultWarehouseException::class);

        try {
            app(SetDefaultWarehouse::class)->handle($inactive, new ChangeDefaultWarehouseData('Invalid target test'), $owner);
        } finally {
            $this->assertTrue(ActivityLog::query()->where('event', 'warehouse.default_change_rejected')->exists());
        }
    }

    public function test_current_default_cannot_be_deactivated(): void
    {
        $owner = $this->owner();
        $candidate = Warehouse::factory()->create();
        app(SetDefaultWarehouse::class)->handle($candidate, new ChangeDefaultWarehouseData('Move default'), $owner);

        $this->expectException(DefaultWarehouseException::class);
        app(SetWarehouseStatus::class)->handle($candidate, new ChangeWarehouseStatusData(false, 'Try deactivate default'), $owner);
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role(EmployeeRole::Owner)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
