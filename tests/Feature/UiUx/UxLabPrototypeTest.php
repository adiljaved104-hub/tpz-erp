<?php

namespace Tests\Feature\UiUx;

use App\Enums\EmployeeRole;
use App\Filament\Pages\UxLab;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UxLabPrototypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_ux_lab_is_owner_only_and_renders_the_read_only_controls(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $staff = Employee::factory()->role(EmployeeRole::Staff)->create()->user;

        $this->actingAs($owner)->get(UxLab::getUrl())->assertOk()->assertSee('TPZ UX Lab')->assertSee('Reset demo');
        $this->actingAs($staff)->get(UxLab::getUrl())->assertForbidden();
    }

    public function test_prototype_contains_no_operational_write_controls(): void
    {
        $view = file_get_contents(resource_path('views/filament/pages/ux-lab.blade.php'));

        $this->assertStringContainsString('read-only prototypes', $view);
        $this->assertStringContainsString('No operational records', $view);
        $this->assertStringNotContainsString('Save & Reserve', $view);
        $this->assertStringNotContainsString('Mark Shipped', $view);
        $this->assertStringContainsString('simulateAction', $view);
        $this->assertStringContainsString('openPreview', $view);
        $this->assertStringContainsString('downloadFeedback', $view);
    }
}
