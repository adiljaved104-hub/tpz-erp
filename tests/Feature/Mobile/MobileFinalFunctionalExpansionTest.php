<?php

namespace Tests\Feature\Mobile;

use App\Enums\EmployeeRole;
use App\Enums\InventoryReservationStatus;
use App\Models\Employee;
use App\Models\InventoryReservation;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\Supplier;
use App\Models\TaxInvoice;
use App\Models\Team;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Mobile\NotificationTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileFinalFunctionalExpansionTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_location_and_team_responses_preserve_authorization_scope(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $supplier = Supplier::factory()->create([
            'name' => 'Mobile Safe Supplier',
            'contact_person' => 'Purchasing Contact',
            'notes' => 'Internal supplier risk notes',
        ]);
        $location = Warehouse::factory()->create(['name' => 'Mobile Main Location', 'code' => 'MOB-LOC']);

        $supplierDetail = $this->as($owner)->getJson('/api/mobile/v1/workspace/suppliers/'.$supplier->id)->assertOk();
        $supplierDetail->assertJsonPath('data.fields.contact_person', 'Purchasing Contact');
        $this->assertArrayNotHasKey('notes', $supplierDetail->json('data.fields'));
        $this->as($staff)->getJson('/api/mobile/v1/workspace/suppliers')->assertForbidden();

        $this->as($owner)->getJson('/api/mobile/v1/workspace/inventory-locations/'.$location->id)
            ->assertOk()->assertJsonPath('data.fields.code', 'MOB-LOC');
        $this->as($staff)->getJson('/api/mobile/v1/workspace/inventory-locations')->assertForbidden();

        $team = Team::query()->create(['name' => 'Mobile Team', 'description' => 'Authorized team', 'status' => true]);
        $otherTeam = Team::query()->create(['name' => 'Other Team', 'description' => 'Unrelated team', 'status' => true]);
        $manager = $this->user(EmployeeRole::Manager, ['team_id' => $team->id]);
        $member = $this->user(EmployeeRole::Staff, ['team_id' => $team->id]);
        $outsider = $this->user(EmployeeRole::Staff, ['team_id' => $otherTeam->id]);

        $teams = $this->as($manager)->getJson('/api/mobile/v1/workspace/hr/teams')->assertOk();
        $this->assertSame([$team->id], collect($teams->json('data'))->pluck('id')->all());
        $detail = $this->as($manager)->getJson('/api/mobile/v1/workspace/hr/teams/'.$team->id)->assertOk();
        $memberIds = collect($detail->json('data.items'))->pluck('id');
        $this->assertContains($manager->employee->id, $memberIds);
        $this->assertContains($member->employee->id, $memberIds);
        $this->assertNotContains($outsider->employee->id, $memberIds);
        $this->as($manager)->getJson('/api/mobile/v1/workspace/hr/teams/'.$otherTeam->id)->assertNotFound();
    }

    public function test_stock_transfer_uses_domain_actions_and_rejects_invalid_transitions(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $source = Warehouse::factory()->create(['status' => true]);
        $destination = Warehouse::factory()->create(['status' => true]);
        $product = Product::factory()->create();
        ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $source->id,
            'available_quantity' => 3,
            'reserved_quantity' => 0,
            'damaged_quantity' => 0,
            'average_cost' => '75.0000',
        ]);

        $create = $this->as($owner)->postJson('/api/mobile/v1/workspace/stock-transfers', [
            'source_warehouse_id' => $source->id,
            'destination_warehouse_id' => $destination->id,
            'transfer_date' => now()->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertOk()->assertJsonPath('data.status', 'draft');
        $transferId = $create->json('data.id');

        $this->as($owner)->postJson('/api/mobile/v1/workspace/stock-transfers/'.$transferId.'/receive', [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertUnprocessable();

        $this->as($owner)->postJson('/api/mobile/v1/workspace/stock-transfers/'.$transferId.'/dispatch', [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('data.status', 'dispatched');
        $this->assertSame(1, ProductInventory::query()->where('product_id', $product->id)
            ->where('warehouse_id', $source->id)->sole()->available_quantity);

        $this->as($owner)->postJson('/api/mobile/v1/workspace/stock-transfers/'.$transferId.'/receive', [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('data.status', 'received');
        $this->assertSame(2, ProductInventory::query()->where('product_id', $product->id)
            ->where('warehouse_id', $destination->id)->sole()->available_quantity);
        $this->as($staff)->getJson('/api/mobile/v1/workspace/stock-transfers')->assertForbidden();
    }

    public function test_reservation_release_uses_inventory_service_and_enforces_scope(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $inventory = ProductInventory::factory()->create([
            'available_quantity' => 3,
            'reserved_quantity' => 2,
            'damaged_quantity' => 0,
        ]);
        $reservation = InventoryReservation::factory()->create([
            'product_inventory_id' => $inventory->id,
            'product_id' => $inventory->product_id,
            'warehouse_id' => $inventory->warehouse_id,
            'quantity' => 2,
            'reserved_by_user_id' => $owner->id,
        ]);

        $this->as($owner)->getJson('/api/mobile/v1/workspace/reservations/'.$reservation->id)
            ->assertOk()->assertJsonPath('data.fields.quantity', 2);
        $this->as($staff)->getJson('/api/mobile/v1/workspace/reservations')->assertForbidden();

        $this->as($owner)->postJson('/api/mobile/v1/workspace/reservations/'.$reservation->id.'/release', [
            'reason' => 'No longer required',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('data.status', InventoryReservationStatus::Released->value);

        $inventory->refresh();
        $this->assertSame(3, $inventory->available_quantity);
        $this->assertSame(0, $inventory->reserved_quantity);
    }

    public function test_invoice_scope_and_financial_redaction_use_invoice_authorization(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $outsider = $this->user(EmployeeRole::Staff);
        $invoice = $this->invoice($staff);

        $staffDetail = $this->as($staff)->getJson('/api/mobile/v1/workspace/invoices/'.$invoice->id)->assertOk();
        $this->assertArrayNotHasKey('grand_total', $staffDetail->json('data.fields'));
        $this->assertArrayNotHasKey('vat_amount', $staffDetail->json('data.fields'));

        $ownerDetail = $this->as($owner)->getJson('/api/mobile/v1/workspace/invoices/'.$invoice->id)->assertOk();
        $ownerDetail->assertJsonPath('data.fields.grand_total', '105.00');
        $this->as($outsider)->getJson('/api/mobile/v1/workspace/invoices/'.$invoice->id)->assertForbidden();
        $this->assertSame([], $this->as($outsider)->getJson('/api/mobile/v1/workspace/invoices')
            ->assertOk()->json('data'));
    }

    public function test_global_search_returns_only_authorized_new_mobile_targets(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $outsider = $this->user(EmployeeRole::Staff);
        $supplier = Supplier::factory()->create(['name' => 'Searchable Mobile Supplier']);
        $location = Warehouse::factory()->create(['name' => 'Searchable Mobile Location', 'code' => 'SML-LOC']);
        $invoice = $this->invoice($owner, 'INV-MOBILE-SEARCH');

        foreach ([
            ['Searchable Mobile Supplier', 'suppliers', $supplier->id],
            ['SML-LOC', 'inventory-locations', $location->id],
            ['INV-MOBILE-SEARCH', 'invoices', $invoice->id],
        ] as [$query, $module, $id]) {
            $response = $this->as($owner)->getJson('/api/mobile/v1/workspace/search?q='.urlencode($query))->assertOk();
            $item = collect($response->json('data'))->flatMap(fn (array $group) => $group['items'])
                ->firstWhere('target.module', $module);
            $this->assertSame($id, $item['target']['id']);
            $this->assertStringNotContainsString('"url"', $response->getContent());
        }

        $denied = $this->as($outsider)->getJson('/api/mobile/v1/workspace/search?q=SML-LOC')->assertOk();
        $this->assertNull(collect($denied->json('data'))->flatMap(fn (array $group) => $group['items'])
            ->firstWhere('target.module', 'inventory-locations'));
        $this->assertNull(app(NotificationTarget::class)->resolve($owner, [
            'target_type' => 'tax_invoice',
            'target_id' => $invoice->id,
        ]));
    }

    private function invoice(User $creator, string $number = 'INV-MOBILE-001'): TaxInvoice
    {
        return TaxInvoice::query()->create([
            'invoice_number' => $number,
            'order_reference' => 'ORD-MOBILE-001',
            'invoice_date' => now()->toDateString(),
            'customer_name' => 'Mobile Customer',
            'customer_address' => 'Dubai',
            'customer_trn' => '100000000000001',
            'vat_rate' => '5.00',
            'subtotal_excluding_vat' => '100.00',
            'vat_amount' => '5.00',
            'grand_total' => '105.00',
            'seller_snapshot' => ['name' => 'TPZ'],
            'terms_en_snapshot' => 'Test terms',
            'terms_ar_snapshot' => null,
            'status' => 'issued',
            'created_by_user_id' => $creator->id,
            'issued_at' => now(),
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    private function user(EmployeeRole $role, array $employee = []): User
    {
        $email = 'mobile-final-'.Str::lower(Str::random(12)).'@techpointzone.com';
        $user = User::factory()->create(['email' => $email]);
        Employee::factory()->for($user)->role($role)->create([
            'email' => $email,
            'status' => true,
            ...$employee,
        ]);

        return $user->refresh();
    }

    private function as(User $user): static
    {
        app('auth')->forgetGuards();

        return $this->withToken($user->createToken('Mobile final expansion test')->plainTextToken);
    }
}
