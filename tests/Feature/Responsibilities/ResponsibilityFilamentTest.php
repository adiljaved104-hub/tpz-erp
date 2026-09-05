<?php

namespace Tests\Feature\Responsibilities;

use App\Enums\EmployeeRole;
use App\Filament\Pages\Inventory\MyInventory;
use App\Filament\Pages\Inventory\ResponsibilityReports;
use App\Filament\Resources\MarketplacePlatforms\MarketplacePlatformResource;
use App\Filament\Resources\ResponsibilityAssignments\Pages\CreateResponsibilityAssignment;
use App\Filament\Resources\ResponsibilityAssignments\ResponsibilityAssignmentResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class ResponsibilityFilamentTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_direct_page_access_and_navigation_follow_server_side_permissions(): void
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $staff = $this->responsibilityUser(EmployeeRole::Staff);

        $this->actingAs($owner);
        $this->assertTrue(ResponsibilityAssignmentResource::canViewAny());
        $this->assertTrue(MarketplacePlatformResource::canViewAny());
        $this->assertTrue(MyInventory::canAccess());
        $this->assertTrue(ResponsibilityReports::canAccess());

        $this->actingAs($staff);
        $this->assertTrue(MyInventory::canAccess());
        $this->assertFalse(MarketplacePlatformResource::canViewAny());
        $this->assertFalse(ResponsibilityReports::canAccess());
        $this->get('/admin/responsibility-reports')->assertForbidden();
        $this->get('/admin/responsibility-assignments/create')->assertForbidden();
        Livewire::test(CreateResponsibilityAssignment::class)->assertForbidden();
    }
}
