<?php

namespace Tests\Feature\Responsibilities;

use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\Enums\EmployeeRole;
use App\Enums\ResponsibilityAssignmentMode;
use App\Exceptions\ResponsibilityCapacityExceededException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class ResponsibilityConcurrencyTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_serialized_writers_recalculate_aggregate_from_locked_inventory_context(): void
    {
        $f = $this->responsibilityFoundation(10, 0);
        $action = app(CreateResponsibilityAssignment::class);
        $action->handle($this->assignmentData($f, ResponsibilityAssignmentMode::Quantity, ['assignedQuantity' => 6]), $f['owner']);
        $second = $this->responsibilityUser(EmployeeRole::Staff);
        $action->handle($this->assignmentData($f, ResponsibilityAssignmentMode::Quantity, ['employeeId' => $second->employee->id, 'assignedQuantity' => 4]), $f['owner']);

        $third = $this->responsibilityUser(EmployeeRole::Staff);
        $this->expectException(ResponsibilityCapacityExceededException::class);
        $action->handle($this->assignmentData($f, ResponsibilityAssignmentMode::Quantity, ['employeeId' => $third->employee->id, 'assignedQuantity' => 1]), $f['owner']);
    }
}
