<?php

namespace Tests\Feature\Mobile;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\OrderPermission;
use App\Enums\ProductPermission;
use App\Enums\PurchasePermission;
use App\Enums\TaskPermission;
use App\Models\EmployeePermissionOverride;
use App\Models\User;
use App\Services\Responsibilities\ResponsibilityAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class MobilePlatformSyncTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    private function asMobile(User $user): static
    {
        $user->forceFill(['email' => 'sync-'.$user->id.'@techpointzone.com'])->save();
        $user->employee->forceFill(['email' => $user->email])->save();
        app('auth')->forgetGuards();

        return $this->withToken($user->createToken('Sync test')->plainTextToken);
    }

    private function override(User $user, string $permission, EmployeePermissionEffect $effect, User $owner): void
    {
        EmployeePermissionOverride::query()->create([
            'employee_id' => $user->employee->id, 'permission_key' => $permission,
            'effect' => $effect, 'granted_by_user_id' => $owner->id,
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($user->employee->id);
    }

    public function test_manifest_requires_authentication(): void
    {
        $this->getJson('/api/mobile/v1/workspace/manifest')->assertUnauthorized();
    }

    public function test_manifest_is_versioned_and_uses_existing_permission_driven_modules(): void
    {
        $f = $this->responsibilityFoundation();
        $url = '/api/mobile/v1/workspace/manifest';
        $owner = $this->asMobile($f['owner'])->getJson($url)->assertOk()
            ->assertJsonPath('schema_version', 1)->assertJsonPath('minimum_runtime_version', 1);
        $keys = collect($owner->json('modules'))->pluck('key');
        $legacy = $this->asMobile($f['owner'])->getJson('/api/mobile/v1/workspace/modules')->assertOk();
        $this->assertSame(collect($legacy->json('data'))->pluck('key')->all(), $keys->all());
        foreach ($owner->json('modules') as $module) {
            foreach (['key', 'title', 'description', 'code', 'group', 'order', 'renderer', 'api_path', 'record_module', 'searchable', 'list', 'detail', 'create', 'edit', 'filters', 'statuses', 'actions', 'features'] as $field) {
                $this->assertArrayHasKey($field, $module);
            }
        }
        $products = collect($owner->json('modules'))->firstWhere('key', 'products');
        $this->assertSame('generic', $products['renderer']);
        $this->assertFalse($products['create']['enabled']);
        $this->assertSame('native', collect($owner->json('modules'))->firstWhere('key', 'chat')['renderer']);
        $staff = $f['employee']->user;
        $staffKeys = collect($this->asMobile($staff)->getJson($url)->assertOk()->json('modules'))->pluck('key');
        $this->assertNotContains('purchases', $staffKeys);
        $this->override($staff, PurchasePermission::View->value, EmployeePermissionEffect::Allow, $f['owner']);
        $purchase = collect($this->asMobile($staff)->getJson($url)->assertOk()->json('modules'))->firstWhere('key', 'purchases');
        $this->assertNotNull($purchase);
        $this->assertFalse($purchase['create']['enabled']);
        $this->asMobile($staff)->postJson('/api/mobile/v1/workspace/purchases', [])->assertForbidden();
        $this->override($staff, OrderPermission::Create->value, EmployeePermissionEffect::Allow, $f['owner']);
        $this->override($staff, OrderPermission::EditSellingPrice->value, EmployeePermissionEffect::Deny, $f['owner']);
        $sales = collect($this->asMobile($staff)->getJson($url)->assertOk()->json('modules'))->firstWhere('key', 'sales');
        $this->assertFalse($sales['create']['enabled']);
        $this->asMobile($staff)->postJson('/api/mobile/v1/workspace/orders', ['mode' => 'draft'])->assertForbidden();
        config(['mobile.minimum_runtime_version' => 2]);
        $this->asMobile($staff)->getJson($url)->assertOk()->assertJsonPath('minimum_runtime_version', 2);
    }

    public function test_product_edit_metadata_and_mutations_remain_authorized(): void
    {
        $f = $this->responsibilityFoundation();
        app(ResponsibilityAssignmentService::class)->create($this->assignmentData($f), $f['owner']);
        $url = '/api/mobile/v1/workspace/products/'.$f['product']->id;
        $owner = $this->asMobile($f['owner'])->getJson($url)->assertOk();
        $field = collect($owner->json('data.actions'))->firstWhere('key', 'update')['fields'][0];
        $this->assertTrue($field['editable']);
        $this->assertTrue($field['mobile_editable']);
        $this->assertFalse($field['read_only']);
        $this->assertArrayHasKey('placeholder', $field);
        $this->assertArrayHasKey('help_text', $field);
        $this->assertFalse($owner->json('data.field_schema.0.editable'));
        $this->assertTrue($owner->json('data.field_schema.0.read_only'));
        $staff = $f['employee']->user;
        $this->override($staff, ProductPermission::Update->value, EmployeePermissionEffect::Deny, $f['owner']);
        $this->asMobile($staff)->getJson($url)->assertOk()->assertJsonPath('data.actions', []);
        $this->asMobile($staff)->postJson($url.'/update', [])->assertForbidden();
    }

    public function test_task_edit_uses_existing_service_validation_and_permissions(): void
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $staff = $this->responsibilityUser(EmployeeRole::Staff);
        $url = '/api/mobile/v1/workspace/tasks';
        $id = $this->asMobile($owner)->postJson($url, [
            'title' => 'Sync task', 'priority' => 'normal', 'assigned_employee_id' => $staff->employee->id, 'idempotency_key' => (string) Str::uuid(),
        ])->assertOk()->json('data.id');
        $detail = $this->asMobile($owner)->getJson($url.'/'.$id)->assertOk();
        $this->assertContains('update', collect($detail->json('data.actions'))->pluck('key'));
        $this->asMobile($owner)->postJson($url.'/'.$id.'/update', ['title' => '', 'priority' => 'invalid'])->assertUnprocessable();
        $this->asMobile($owner)->postJson($url.'/'.$id.'/update', [
            'title' => 'Edited from mobile', 'description' => 'ERP service update', 'priority' => 'high', 'due_at' => '2026-12-01',
        ])->assertOk()->assertJsonPath('data.title', 'Edited from mobile')->assertJsonPath('data.fields.priority', 'high');
        $this->assertDatabaseHas('task_events', ['task_id' => $id, 'event_type' => 'priority_changed']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'task.updated', 'subject_id' => $id]);
        $this->override($staff, TaskPermission::Update->value, EmployeePermissionEffect::Deny, $owner);
        $detail = $this->asMobile($staff)->getJson($url.'/'.$id)->assertOk();
        $this->assertNotContains('update', collect($detail->json('data.actions'))->pluck('key'));
        $this->asMobile($staff)->postJson($url.'/'.$id.'/update', ['title' => 'Forbidden', 'priority' => 'low'])->assertForbidden();
    }

    public function test_public_app_version_contract_has_safe_defaults_and_configuration(): void
    {
        $this->getJson('/api/mobile/v1/app/version')->assertOk()
            ->assertJsonPath('data.latest_build', 0)->assertJsonPath('data.minimum_build', 0)
            ->assertJsonPath('data.update_required', false)
            ->assertJsonPath('data.download_url', 'https://tpzerp.cloud/downloads/tpz-erp.apk')
            ->assertJsonStructure(['data' => ['latest_version', 'latest_build', 'minimum_build', 'update_required', 'download_url', 'message']]);
        config(['mobile.app.latest_version' => '1.5.0', 'mobile.app.latest_build' => 12,
            'mobile.app.minimum_build' => 11, 'mobile.app.update_required' => true]);
        $this->getJson('/api/mobile/v1/app/version')->assertOk()->assertJsonPath('data.latest_version', '1.5.0')
            ->assertJsonPath('data.latest_build', 12)->assertJsonPath('data.minimum_build', 11)->assertJsonPath('data.update_required', true);
    }
}
