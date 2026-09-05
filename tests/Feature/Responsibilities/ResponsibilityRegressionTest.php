<?php

namespace Tests\Feature\Responsibilities;

use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\Enums\ResponsibilityAssignmentMode;
use App\Models\InventoryReservation;
use App\Models\Purchase;
use App\Models\PurchaseReceipt;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class ResponsibilityRegressionTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_responsibility_workflow_does_not_touch_physical_or_purchasing_records(): void
    {
        $f = $this->responsibilityFoundation(10, 2);
        $before = [
            'available_quantity' => $f['inventory']->available_quantity,
            'reserved_quantity' => $f['inventory']->reserved_quantity,
            'damaged_quantity' => $f['inventory']->damaged_quantity,
            'average_cost' => $f['inventory']->average_cost,
        ];
        $counts = [Purchase::query()->count(), PurchaseReceipt::query()->count(), InventoryReservation::query()->count(), StockMovement::query()->count()];
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, ResponsibilityAssignmentMode::Quantity, ['assignedQuantity' => 5]), $f['owner']);

        $inventory = $f['inventory']->refresh();
        $this->assertSame($before, [
            'available_quantity' => $inventory->available_quantity,
            'reserved_quantity' => $inventory->reserved_quantity,
            'damaged_quantity' => $inventory->damaged_quantity,
            'average_cost' => $inventory->average_cost,
        ]);
        $this->assertSame($counts, [Purchase::query()->count(), PurchaseReceipt::query()->count(), InventoryReservation::query()->count(), StockMovement::query()->count()]);
    }
}
