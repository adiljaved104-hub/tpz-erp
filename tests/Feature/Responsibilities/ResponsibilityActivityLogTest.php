<?php

namespace Tests\Feature\Responsibilities;

use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\Enums\ResponsibilityAssignmentMode;
use App\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class ResponsibilityActivityLogTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_activity_is_single_safe_event_without_financial_or_authentication_values(): void
    {
        $f = $this->responsibilityFoundation();
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, ResponsibilityAssignmentMode::Quantity, ['assignedQuantity' => 4]), $f['owner']);
        $log = ActivityLog::query()->where('event', 'responsibility.created')->sole();
        $json = strtolower($log->toJson());

        $this->assertStringContainsString('assigned_quantity', $json);
        foreach (['cost', 'price', 'value', 'password', 'token', 'credential'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }
}
