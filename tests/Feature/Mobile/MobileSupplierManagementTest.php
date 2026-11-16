<?php

namespace Tests\Feature\Mobile;

use App\Enums\EmployeeRole;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileSupplierManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_creates_updates_and_changes_supplier_status_through_existing_actions(): void
    {
        $owner = $this->user(EmployeeRole::Owner);

        $options = $this->as($owner)->getJson('/api/mobile/v1/workspace/suppliers/options')->assertOk();
        $options->assertJsonPath('data.can_create', true);
        $this->assertSame(
            ['name', 'contact_person', 'phone', 'email', 'address', 'vat_number', 'notes'],
            collect($options->json('data.fields'))->pluck('name')->all(),
        );

        $created = $this->as($owner)->postJson('/api/mobile/v1/workspace/suppliers', [
            'name' => 'Mobile Supplier',
            'contact_person' => 'Purchasing Contact',
            'phone' => '+971500000001',
            'email' => 'supplier@example.com',
            'address' => 'Dubai',
            'vat_number' => 'VAT-001',
            'notes' => 'Owner-only operational note',
        ])->assertCreated()->assertJsonPath('data.status', 'active');
        $supplierId = $created->json('data.id');
        $this->assertSame(
            'Owner-only operational note',
            collect($created->json('data.actions'))->firstWhere('key', 'update')['fields'][6]['value'],
        );

        $updated = $this->as($owner)->postJson('/api/mobile/v1/workspace/suppliers/'.$supplierId.'/update', [
            'name' => 'Updated Mobile Supplier',
            'contact_person' => 'New Contact',
            'phone' => null,
            'email' => 'updated@example.com',
            'address' => 'Abu Dhabi',
            'vat_number' => 'VAT-002',
            'notes' => 'Updated internal note',
        ])->assertOk();
        $updated->assertJsonPath('data.title', 'Updated Mobile Supplier');
        $this->assertSame(
            'VAT-002',
            collect($updated->json('data.actions'))->firstWhere('key', 'update')['fields'][5]['value'],
        );

        $this->as($owner)->postJson('/api/mobile/v1/workspace/suppliers/'.$supplierId.'/deactivate', [
            'reason' => 'Temporarily paused',
        ])->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->as($owner)->postJson('/api/mobile/v1/workspace/suppliers/'.$supplierId.'/activate', [
            'reason' => 'Approved for new orders',
        ])->assertOk()->assertJsonPath('data.status', 'active');

        $this->assertSame(
            ['supplier.created', 'supplier.updated', 'supplier.status_changed', 'supplier.status_changed'],
            ActivityLog::query()->where('subject_id', $supplierId)
                ->whereIn('event', ['supplier.created', 'supplier.updated', 'supplier.status_changed'])
                ->orderBy('id')->pluck('event')->all(),
        );
    }

    public function test_manager_view_remains_safe_and_management_endpoints_are_permission_gated(): void
    {
        $manager = $this->user(EmployeeRole::Manager);
        $supplier = Supplier::factory()->create([
            'vat_number' => 'PRIVATE-VAT',
            'notes' => 'Private supplier note',
        ]);

        $detail = $this->as($manager)->getJson('/api/mobile/v1/workspace/suppliers/'.$supplier->id)->assertOk();
        $this->assertArrayNotHasKey('vat_number', $detail->json('data.fields'));
        $this->assertArrayNotHasKey('notes', $detail->json('data.fields'));
        $this->assertSame([], $detail->json('data.actions'));
        $this->as($manager)->getJson('/api/mobile/v1/workspace/suppliers/options')
            ->assertOk()->assertJsonPath('data.can_create', false)->assertJsonPath('data.fields', []);

        $this->as($manager)->postJson('/api/mobile/v1/workspace/suppliers', ['name' => 'Denied'])
            ->assertForbidden();
        $this->as($manager)->postJson('/api/mobile/v1/workspace/suppliers/'.$supplier->id.'/update', [
            'name' => 'Denied update',
        ])->assertForbidden();
        $this->as($manager)->postJson('/api/mobile/v1/workspace/suppliers/'.$supplier->id.'/deactivate', [
            'reason' => 'Denied status change',
        ])->assertForbidden();
        $this->assertSame('PRIVATE-VAT', $supplier->refresh()->vat_number);
    }

    public function test_operationally_used_supplier_cannot_be_deactivated_and_validation_is_safe(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $supplier = Supplier::factory()->create(['status' => true]);
        Purchase::factory()->create(['supplier_id' => $supplier->id]);

        $this->as($owner)->postJson('/api/mobile/v1/workspace/suppliers/'.$supplier->id.'/deactivate', [
            'reason' => 'Attempt to deactivate used supplier',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertTrue($supplier->refresh()->status);

        $this->as($owner)->postJson('/api/mobile/v1/workspace/suppliers/'.$supplier->id.'/deactivate', [
            'reason' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->as($owner)->postJson('/api/mobile/v1/workspace/suppliers/'.$supplier->id.'/unknown')
            ->assertNotFound();
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create(['email' => 'mobile-supplier-'.Str::lower(Str::random(12)).'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'status' => true]);

        return $user->refresh();
    }

    private function as(User $user): static
    {
        app('auth')->forgetGuards();

        return $this->withToken($user->createToken('Mobile supplier management')->plainTextToken);
    }
}
