<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\SaveAndReserveOrder;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\InventoryAllocationMode;
use App\Enums\InventoryPermission;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\InventoryAllocationEvent;
use App\Models\InventoryAllocationSetting;
use App\Models\Order;
use App\Models\Team;
use App\Models\User;
use App\Services\Authorization\EmployeePermissionOverrideService;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Orders\OrderAmendmentService;
use App\Services\Responsibilities\ResponsibilityAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class OrderAmendmentStockSourceAccessTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_staff_and_manager_amendment_source_controls_require_explicit_permission(): void
    {
        $foundation = $this->foundation();
        $actor = $foundation['employee']->user;
        $order = $this->reserve($foundation, $actor);
        foreach ([EmployeeRole::Staff, EmployeeRole::Manager] as $role) {
            $actor->employee->update(['role' => $role]);
            $actor = $actor->fresh();
            $component = Livewire::actingAs($actor)->test(ViewOrder::class, ['record' => $order->getRouteKey()])
                ->mountAction('amendOrder')->assertActionMounted('amendOrder');
            $this->assertSourceControls($component, false);
        }
        $this->grantManual($actor, $foundation['owner'], EmployeePermissionEffect::Allow);
        foreach ([EmployeeRole::Staff, EmployeeRole::Manager] as $role) {
            $actor->employee->update(['role' => $role]);
            $actor = $actor->fresh();
            $component = Livewire::actingAs($actor)->test(ViewOrder::class, ['record' => $order->getRouteKey()])
                ->mountAction('amendOrder')->assertActionMounted('amendOrder');
            $this->assertSourceControls($component, true);
        }
    }

    public function test_owner_admin_keep_manual_amendment_ui_and_admin_deny_is_respected(): void
    {
        $foundation = $this->foundation();
        $order = $this->reserve($foundation, $foundation['employee']->user);
        $admin = $this->responsibilityUser(EmployeeRole::Admin);
        foreach ([$foundation['owner'], $admin] as $actor) {
            $component = Livewire::actingAs($actor)->test(ViewOrder::class, ['record' => $order->getRouteKey()])
                ->mountAction('amendOrder');
            $this->assertSourceControls($component, true);
        }
        $this->grantManual($admin, $foundation['owner'], EmployeePermissionEffect::Deny);
        $component = Livewire::actingAs($admin->fresh())->test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->mountAction('amendOrder');
        $this->assertSourceControls($component, false);
    }

    public function test_crafted_own_team_foreign_and_empty_manual_payloads_are_rejected_without_mutation(): void
    {
        $foundation = $this->foundation();
        $actor = $foundation['employee']->user;
        $order = $this->reserve($foundation, $actor);
        $events = InventoryAllocationEvent::query()->count();
        foreach ([EmployeeRole::Staff, EmployeeRole::Manager] as $role) {
            $actor->employee->update(['role' => $role]);
            $actor = $actor->fresh();
            foreach ([$foundation['own']->id, $foundation['team_account']->id, $foundation['foreign']->id, null] as $accountId) {
                try {
                    app(OrderAmendmentService::class)->amend($order, $this->input($order, 2,
                        $accountId === null ? [] : [['account_id' => $accountId, 'quantity' => 1]]), $actor);
                    $this->fail('All manually submitted sources require the permission.');
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('items.0.allocation_sources', $exception->errors());
                    $this->assertStringContainsString('not authorized', $exception->getMessage());
                }
            }
        }
        $this->assertSame(1, $order->items()->sole()->reservation->quantity);
        $this->assertDatabaseCount('order_amendments', 0);
        $this->assertSame($events, InventoryAllocationEvent::query()->count());
    }

    public function test_automatic_increase_uses_existing_own_then_team_and_retry_does_not_duplicate_events(): void
    {
        $foundation = $this->foundation();
        $actor = $foundation['employee']->user;
        $order = $this->reserve($foundation, $actor);
        $this->strictMode();
        $input = $this->input($order, 3);
        $service = app(OrderAmendmentService::class);
        $amendment = $service->amend($order, $input, $actor);
        $events = InventoryAllocationEvent::query()->count();
        $this->assertSame($amendment->id, $service->amend($order, $input, $actor)->id);
        $this->assertSame($events, InventoryAllocationEvent::query()->count());
        $this->assertSame(2, $this->reserved($foundation, 'own'));
        $this->assertSame(1, $this->reserved($foundation, 'team_account'));
        $this->assertSame(0, $this->reserved($foundation, 'foreign'));
        $this->assertSame(3, $foundation['inventory']->fresh()->reserved_quantity);
        $this->assertSame(8, $foundation['inventory']->fresh()->available_quantity);
        $this->assertDatabaseCount('inventory_allocation_reservation_lines', 2);
        $this->assertDatabaseCount('order_amendments', 1);
    }

    public function test_revoked_manual_permission_cannot_increase_an_existing_foreign_source(): void
    {
        $foundation = $this->foundation();
        $actor = $foundation['employee']->user;
        $this->grantManual($actor, $foundation['owner'], EmployeePermissionEffect::Allow);
        $order = $this->reserve($foundation, $actor->fresh(), [$foundation['foreign']->id => 1]);
        $this->grantManual($actor, $foundation['owner'], EmployeePermissionEffect::Deny);
        $this->strictMode();
        app(OrderAmendmentService::class)->amend($order, $this->input($order, 2), $actor->fresh());
        $this->assertSame(1, $this->reserved($foundation, 'foreign'));
        $this->assertSame(1, $this->reserved($foundation, 'own'));
        $this->assertSame(2, $order->items()->sole()->reservation->quantity);
    }

    public function test_insufficient_authorized_increase_rolls_back_without_foreign_or_strict_system_fallback(): void
    {
        $foundation = $this->foundation();
        $actor = $foundation['employee']->user;
        $order = $this->reserve($foundation, $actor);
        $this->strictMode();
        $events = InventoryAllocationEvent::query()->count();
        try {
            app(OrderAmendmentService::class)->amend($order, $this->input($order, 5), $actor);
            $this->fail('Physical and foreign stock must not cover insufficient authorized stock.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Stock Request', $exception->getMessage());
        }
        $this->assertSame(1, $this->reserved($foundation, 'own'));
        $this->assertSame(0, $this->reserved($foundation, 'team_account'));
        $this->assertSame(0, $this->reserved($foundation, 'foreign'));
        $this->assertSame(0, $this->reserved($foundation, 'system'));
        $this->assertSame(1, $order->items()->sole()->ordered_quantity);
        $this->assertSame(1, $foundation['inventory']->fresh()->reserved_quantity);
        $this->assertSame($events, InventoryAllocationEvent::query()->count());
        $this->assertDatabaseCount('order_amendments', 0);
    }

    public function test_shadow_system_fallback_is_last_and_audited_but_not_available_in_strict_mode(): void
    {
        $foundation = $this->foundation();
        $actor = $foundation['employee']->user;
        $order = $this->reserve($foundation, $actor);
        app(OrderAmendmentService::class)->amend($order, $this->input($order, 5), $actor);
        $this->assertSame(2, $this->reserved($foundation, 'own'));
        $this->assertSame(2, $this->reserved($foundation, 'team_account'));
        $this->assertSame(1, $this->reserved($foundation, 'system'));
        $this->assertSame(0, $this->reserved($foundation, 'foreign'));
        $event = InventoryAllocationEvent::query()->where('event_type', 'reservation_increase')
            ->where('from_account_id', $foundation['system']->id)->sole();
        $this->assertTrue($event->metadata['legacy_system_source']);
        $this->assertSame('automatic', $event->metadata['selection']);
        $this->strictMode();
        $this->expectException(ValidationException::class);
        app(OrderAmendmentService::class)->amend($order->fresh(), $this->input($order, 6), $actor);
    }

    public function test_hidden_stale_amendment_sources_are_not_dehydrated_and_own_stock_is_used(): void
    {
        $foundation = $this->foundation();
        $actor = $foundation['employee']->user;
        $order = $this->reserve($foundation, $actor);
        $this->strictMode();
        Livewire::actingAs($actor)->test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->mountAction('amendOrder')
            ->fillForm(fn (array $state): array => ['reason' => 'Customer requests another unit',
                'items' => [array_key_first($state['items']) => ['quantity' => 2,
                    'allocation_sources' => [['account_id' => $foundation['foreign']->id, 'quantity' => 1]]]]])
            ->callMountedAction()
            ->assertHasNoActionErrors();
        $this->assertSame(2, $order->items()->sole()->ordered_quantity);
        $this->assertSame(2, $this->reserved($foundation, 'own'));
        $this->assertSame(0, $this->reserved($foundation, 'foreign'));
    }

    public function test_permitted_actor_still_requires_explicit_additional_sources_and_can_split_them(): void
    {
        $foundation = $this->foundation();
        $actor = $foundation['employee']->user;
        $order = $this->reserve($foundation, $actor);
        $this->grantManual($actor, $foundation['owner'], EmployeePermissionEffect::Allow);
        $actor = $actor->fresh();
        try {
            app(OrderAmendmentService::class)->amend($order, $this->input($order, 3), $actor);
            $this->fail('Manual-source actors must supply an explicit split.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Select an additional Stock Source', $exception->getMessage());
        }
        app(OrderAmendmentService::class)->amend($order, $this->input($order, 3, [
            ['account_id' => $foundation['foreign']->id, 'quantity' => 1],
            ['account_id' => $foundation['team_account']->id, 'quantity' => 1],
        ]), $actor);
        $this->assertSame(1, $this->reserved($foundation, 'own'));
        $this->assertSame(1, $this->reserved($foundation, 'foreign'));
        $this->assertSame(1, $this->reserved($foundation, 'team_account'));
        $this->assertDatabaseCount('inventory_allocation_reservation_lines', 3);
        $this->assertSame(2, InventoryAllocationEvent::query()->where('event_type', 'reservation_increase')
            ->where('metadata->selection', 'explicit')->count());
    }

    private function assertSourceControls(object $component, bool $visible): void
    {
        $instance = $component->instance();
        $schema = $instance->getSchema($instance->getMountedActionSchemaName());
        $field = collect($schema->getFlatFields(withHidden: true))->first(fn ($field): bool => $field->getName() === 'allocation_sources');
        $this->assertNotNull($field);
        $this->assertSame($visible, $field->isVisible());
        $this->assertSame($visible, $field->isDehydrated());
        $html = $schema->toHtml();
        foreach (['Additional Stock Source', 'Add Stock Source'] as $label) {
            if ($visible) {
                $this->assertStringContainsString($label, $html);
            } else {
                $this->assertStringNotContainsString($label, $html);
            }
        }
        if (! $visible) {
            $this->assertStringNotContainsString('Consume From', $html);
        }
    }

    private function foundation(): array
    {
        $foundation = $this->responsibilityFoundation(8);
        $team = Team::query()->create(['name' => 'Sales Team', 'status' => true]);
        $foundation['employee']->update(['team_id' => $team->id]);
        app(ResponsibilityAssignmentService::class)->create($this->assignmentData($foundation), $foundation['owner']);
        $service = app(InventoryAllocationService::class);
        $service->ensureShadowCoverage($foundation['inventory'], $foundation['owner']);
        $foundation['own'] = $service->employeeAccount($foundation['employee']->id);
        $foundation['team_account'] = $service->teamAccount($team->id);
        $foundation['foreign'] = $service->employeeAccount($foundation['owner']->employee->id);
        $foundation['system'] = $service->systemAccount();
        foreach (['own' => 2, 'team_account' => 2, 'foreign' => 3] as $key => $quantity) {
            $service->reconcile($foundation['inventory'], $foundation[$key], $quantity, $foundation['owner'], 'Explicit test ownership');
        }

        return $foundation;
    }

    private function reserve(array $foundation, User $actor, ?array $sources = null): Order
    {
        return app(SaveAndReserveOrder::class)->handle(new SaveAndReserveOrderData($foundation['inventory']->warehouse_id,
            null, null, now()->toDateString(), $actor->employee->id, null,
            [new OrderItemData($foundation['product']->id, 1, '100.00', allocationSources: $sources)], (string) Str::uuid()), $actor);
    }

    private function input(Order $order, int $quantity, ?array $sources = null): array
    {
        return ['reason' => 'Customer changed the requested quantity', 'idempotency_key' => (string) Str::uuid(),
            'items' => [['id' => $order->items()->sole()->id, 'quantity' => $quantity,
                ...($sources === null ? [] : ['allocation_sources' => $sources])]]];
    }

    private function reserved(array $foundation, string $key): int
    {
        return (int) $foundation[$key]->balances()->where('product_inventory_id', $foundation['inventory']->id)->value('reserved_quantity');
    }

    private function grantManual(User $actor, User $owner, EmployeePermissionEffect $effect): void
    {
        app(EmployeePermissionOverrideService::class)->change($actor->employee,
            InventoryPermission::ConsumeFromAllAllocations->value, $effect, null, $owner);
    }

    private function strictMode(): void
    {
        InventoryAllocationSetting::query()->whereKey(1)->update(['enforcement_mode' => InventoryAllocationMode::Strict->value]);
    }
}
