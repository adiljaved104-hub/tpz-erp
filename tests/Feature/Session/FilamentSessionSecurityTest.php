<?php

namespace Tests\Feature\Session;

use App\Enums\EmployeeRole;
use App\Filament\Resources\Purchases\Pages\CreatePurchase;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Widgets\PendingPurchaseReceivingStats;
use App\Filament\Widgets\TaskSupervisionOverview;
use App\Models\Employee;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Mechanisms\ComponentRegistry;
use Tests\TestCase;

class FilamentSessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_cookie_is_application_specific_and_safe_for_local_http(): void
    {
        $this->assertSame('http://127.0.0.1:8000', config('app.url'));
        $this->assertSame('tpz-erp-session', config('session.cookie'));
        $this->assertNull(config('session.domain'));
        $this->assertNull(config('session.secure'));
        $this->assertSame('lax', config('session.same_site'));
        $this->assertSame('/', config('session.path'));
    }

    public function test_filament_keeps_csrf_middleware_and_uses_a_same_origin_livewire_endpoint(): void
    {
        $this->assertContains(PreventRequestForgery::class, Filament::getPanel('admin')->getMiddleware());

        $response = $this->withServerVariables(['HTTP_HOST' => '127.0.0.1:8000'])
            ->get('/admin/login')
            ->assertOk();

        $response->assertSee('data-update-uri="/livewire/update"', false);
        $response->assertSee('name="csrf-token"', false);
        $response->assertDontSee('http://localhost', false);
    }

    public function test_authenticated_task_and_purchase_livewire_mutations_keep_the_session(): void
    {
        $owner = $this->owner();

        Livewire::actingAs($owner)
            ->test(CreateTask::class)
            ->fillForm(['title' => 'Session-safe Task'])
            ->assertSet('data.title', 'Session-safe Task')
            ->assertStatus(200);

        Livewire::actingAs($owner)
            ->test(CreatePurchase::class)
            ->fillForm(['notes' => 'Session-safe Purchase'])
            ->assertSet('data.notes', 'Session-safe Purchase')
            ->assertStatus(200);
    }

    public function test_page_specific_task_and_purchase_widgets_are_registered_for_livewire_updates(): void
    {
        $registry = app(ComponentRegistry::class);

        $this->assertSame(TaskSupervisionOverview::class, $registry->getClass($registry->getName(TaskSupervisionOverview::class)));
        $this->assertSame(PendingPurchaseReceivingStats::class, $registry->getClass($registry->getName(PendingPurchaseReceivingStats::class)));
    }

    public function test_unauthenticated_filament_mutations_are_rejected(): void
    {
        Livewire::test(CreateTask::class)->assertForbidden();
        Livewire::test(CreatePurchase::class)->assertForbidden();
    }

    private function owner(): User
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create();

        return $owner->refresh();
    }
}
