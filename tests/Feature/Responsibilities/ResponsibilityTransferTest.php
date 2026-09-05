<?php

namespace Tests\Feature\Responsibilities;

use App\Actions\Responsibilities\ChangeResponsibilityQuantity;
use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\Actions\Responsibilities\DeactivateResponsibilityAssignment;
use App\Actions\Responsibilities\TransferResponsibilityAssignment;
use App\DTOs\Responsibilities\ChangeResponsibilityQuantityData;
use App\DTOs\Responsibilities\DeactivateResponsibilityAssignmentData;
use App\DTOs\Responsibilities\TransferResponsibilityAssignmentData;
use App\Enums\EmployeeRole;
use App\Enums\ResponsibilityAssignmentMode;
use App\Enums\ResponsibilityAssignmentStatus;
use App\Models\ResponsibilityAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class ResponsibilityTransferTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_transfer_clears_historical_fingerprint_and_creates_linked_successor(): void
    {
        $f = $this->responsibilityFoundation();
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);
        $destination = $this->responsibilityUser(EmployeeRole::Staff);
        $successor = app(TransferResponsibilityAssignment::class)->handle($source, new TransferResponsibilityAssignmentData($destination->employee->id, 'Handover', (string) Str::uuid()), $f['owner']);

        $this->assertSame(ResponsibilityAssignmentStatus::Transferred, $source->refresh()->status);
        $this->assertNull($source->active_fingerprint);
        $this->assertNotNull($source->ended_at);
        $this->assertSame($source->id, $successor->predecessor_assignment_id);
        $this->assertSame($destination->employee->id, $successor->employee_id);
        $this->assertNotNull($successor->active_fingerprint);
    }

    public function test_quantity_change_supersedes_and_deactivation_releases_fingerprint_for_reuse(): void
    {
        $f = $this->responsibilityFoundation();
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, ResponsibilityAssignmentMode::Quantity, ['assignedQuantity' => 5]), $f['owner']);
        $successor = app(ChangeResponsibilityQuantity::class)->handle($source, new ChangeResponsibilityQuantityData(7, 'Updated allocation', (string) Str::uuid()), $f['owner']);

        $this->assertSame(ResponsibilityAssignmentStatus::Superseded, $source->refresh()->status);
        $this->assertNull($source->active_fingerprint);
        $this->assertSame(7, $successor->quantityScope->assigned_quantity);

        app(DeactivateResponsibilityAssignment::class)->handle($successor, new DeactivateResponsibilityAssignmentData('Responsibility ended'), $f['owner']);
        $this->assertSame(ResponsibilityAssignmentStatus::Inactive, $successor->refresh()->status);
        $this->assertNull($successor->active_fingerprint);

        $replacement = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, ResponsibilityAssignmentMode::Quantity, ['assignedQuantity' => 5]), $f['owner']);
        $this->assertSame(3, ResponsibilityAssignment::query()->count());
        $this->assertNotNull($replacement->active_fingerprint);
    }
}
