<?php

namespace Tests\Feature\Responsibilities;

use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\Actions\Responsibilities\DeactivateResponsibilityAssignment;
use App\Actions\Responsibilities\DeactivateResponsibilityAssignments;
use App\DTOs\Responsibilities\DeactivateResponsibilityAssignmentData;
use App\Enums\ResponsibilityAssignmentMode;
use App\Enums\ResponsibilityAssignmentStatus;
use App\Filament\Resources\ResponsibilityAssignments\Pages\ListResponsibilityAssignments;
use App\Models\ActivityLog;
use App\Models\InventoryReservation;
use App\Models\ResponsibilityAssignment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class ResponsibilityBulkDeactivationTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_authorized_bulk_deactivation_ends_every_assignment_and_logs_each_change(): void
    {
        $f = $this->responsibilityFoundation();
        $scope = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);
        $quantity = app(CreateResponsibilityAssignment::class)->handle(
            $this->assignmentData($f, ResponsibilityAssignmentMode::Quantity, ['assignedQuantity' => 4]),
            $f['owner'],
        );
        $ended = app(DeactivateResponsibilityAssignments::class)->handle(
            collect([$quantity, $scope]),
            new DeactivateResponsibilityAssignmentData('End both operational scopes'),
            $f['owner'],
        );

        $this->assertCount(2, $ended);
        $this->assertSame([$scope->id, $quantity->id], $ended->pluck('id')->all());
        $this->assertTrue($ended->every(fn (ResponsibilityAssignment $assignment): bool => $assignment->status === ResponsibilityAssignmentStatus::Inactive));
        $this->assertTrue($ended->every(fn (ResponsibilityAssignment $assignment): bool => $assignment->ended_at !== null && $assignment->ended_by_user_id === $f['owner']->id && $assignment->active_fingerprint === null));
        $this->assertSame(2, ActivityLog::query()->where('event', 'responsibility.deactivated')->count());
    }

    public function test_one_blocked_quantity_assignment_rolls_back_the_entire_bulk_operation(): void
    {
        $f = $this->responsibilityFoundation();
        $scope = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);
        $quantity = app(CreateResponsibilityAssignment::class)->handle(
            $this->assignmentData($f, ResponsibilityAssignmentMode::Quantity, ['assignedQuantity' => 4]),
            $f['owner'],
        );
        $inactive = app(CreateResponsibilityAssignment::class)->handle(
            $this->assignmentData($f, overrides: ['brandId' => null, 'productId' => $f['product']->id]),
            $f['owner'],
        );
        app(DeactivateResponsibilityAssignment::class)->handle($inactive, new DeactivateResponsibilityAssignmentData('Already inactive'), $f['owner']);
        $reservation = InventoryReservation::factory()->create([
            'product_inventory_id' => $f['inventory']->id,
            'product_id' => $f['product']->id,
            'warehouse_id' => $f['inventory']->warehouse_id,
            'quantity' => 1,
        ]);
        DB::table('responsibility_inventory_consumptions')->insert([
            'responsibility_assignment_id' => $quantity->id,
            'inventory_reservation_id' => $reservation->id,
            'order_fulfillment_item_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(DeactivateResponsibilityAssignments::class)->handle(
                [$scope, $quantity, $inactive],
                new DeactivateResponsibilityAssignmentData('Attempt atomic deactivation'),
                $f['owner'],
            );
            $this->fail('An active attributed reservation must block the entire batch.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($quantity->reference, implode(' ', $exception->errors()['reason']));
            $this->assertStringContainsString('Active reservations', implode(' ', $exception->errors()['reason']));
            $this->assertStringContainsString($inactive->reference, implode(' ', $exception->errors()['reason']));
            $this->assertStringContainsString('no longer active', implode(' ', $exception->errors()['reason']));
        }

        $this->assertSame(2, ResponsibilityAssignment::query()->active()->count());
        $this->assertSame(1, ActivityLog::query()->where('event', 'responsibility.deactivated')->count());
    }

    public function test_non_active_selection_is_reported_and_changes_none(): void
    {
        $f = $this->responsibilityFoundation();
        $inactive = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);
        app(DeactivateResponsibilityAssignment::class)->handle($inactive, new DeactivateResponsibilityAssignmentData('Already ended'), $f['owner']);
        $active = app(CreateResponsibilityAssignment::class)->handle(
            $this->assignmentData($f, overrides: ['brandId' => null, 'productId' => $f['product']->id]),
            $f['owner'],
        );

        try {
            app(DeactivateResponsibilityAssignments::class)->handle(
                [$active, $inactive],
                new DeactivateResponsibilityAssignmentData('Attempt mixed selection'),
                $f['owner'],
            );
            $this->fail('A non-active selection must reject the entire batch.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($inactive->reference, implode(' ', $exception->errors()['reason']));
        }

        $this->assertSame(ResponsibilityAssignmentStatus::Active, $active->refresh()->status);
    }

    public function test_unauthorized_employee_cannot_bulk_deactivate_assignments(): void
    {
        $f = $this->responsibilityFoundation();
        $assignment = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);

        $this->expectException(AuthorizationException::class);
        app(DeactivateResponsibilityAssignments::class)->handle(
            [$assignment],
            new DeactivateResponsibilityAssignmentData('Unauthorized attempt'),
            $f['employee']->user,
        );
    }

    public function test_active_inactive_and_all_history_tabs_use_persisted_statuses(): void
    {
        $f = $this->responsibilityFoundation();
        $inactive = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);
        app(DeactivateResponsibilityAssignment::class)->handle($inactive, new DeactivateResponsibilityAssignmentData('End historical scope'), $f['owner']);
        $active = app(CreateResponsibilityAssignment::class)->handle(
            $this->assignmentData($f, overrides: ['brandId' => null, 'productId' => $f['product']->id]),
            $f['owner'],
        );

        Livewire::actingAs($f['owner'])->test(ListResponsibilityAssignments::class)
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$inactive])
            ->set('activeTab', 'inactive')
            ->assertCanSeeTableRecords([$inactive])
            ->assertCanNotSeeTableRecords([$active])
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$active, $inactive]);
    }

    public function test_filament_bulk_action_accepts_selected_rows_and_one_reason(): void
    {
        $f = $this->responsibilityFoundation();
        $first = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);
        $second = app(CreateResponsibilityAssignment::class)->handle(
            $this->assignmentData($f, overrides: ['brandId' => null, 'productId' => $f['product']->id]),
            $f['owner'],
        );

        Livewire::actingAs($f['owner'])->test(ListResponsibilityAssignments::class)
            ->assertTableBulkActionExists('deactivateSelected')
            ->callTableBulkAction('deactivateSelected', [$first, $second], ['reason' => 'End selected operating scopes'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(2, ResponsibilityAssignment::query()->where('status', ResponsibilityAssignmentStatus::Inactive->value)->count());
    }
}
