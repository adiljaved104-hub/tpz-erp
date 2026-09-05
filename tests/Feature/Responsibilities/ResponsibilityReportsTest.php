<?php

namespace Tests\Feature\Responsibilities;

use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\Enums\ResponsibilityAssignmentMode;
use App\Services\Responsibilities\ResponsibilityReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class ResponsibilityReportsTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_reports_include_dimensions_over_assignment_unassigned_and_history(): void
    {
        $f = $this->responsibilityFoundation(5, 0);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, ResponsibilityAssignmentMode::Quantity, ['assignedQuantity' => 5, 'platformId' => $f['platform']->id]), $f['owner']);
        $f['inventory']->forceFill(['reserved_quantity' => 1])->save();
        $reports = app(ResponsibilityReportService::class)->summaries();

        foreach (['by_employee', 'by_brand', 'by_platform', 'by_product', 'over_assigned', 'unassigned_products', 'history'] as $key) {
            $this->assertArrayHasKey($key, $reports);
        }
        $this->assertCount(1, $reports['over_assigned']);
        $this->assertCount(1, $reports['by_platform']);
        $this->assertCount(1, $reports['by_product']);
        $this->assertCount(1, $reports['by_brand']);
    }
}
