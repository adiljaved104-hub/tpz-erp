<?php

namespace Database\Factories;

use App\Enums\ResponsibilityAssignmentMode;
use App\Enums\ResponsibilityAssignmentStatus;
use App\Models\Employee;
use App\Models\ResponsibilityAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ResponsibilityAssignment> */
class ResponsibilityAssignmentFactory extends Factory
{
    protected $model = ResponsibilityAssignment::class;

    public function definition(): array
    {
        return [
            'reference' => 'RA-'.fake()->unique()->numerify('######'),
            'employee_id' => Employee::factory(),
            'team_id_at_assignment' => null,
            'team_name_at_assignment' => null,
            'assignment_mode' => ResponsibilityAssignmentMode::Scope,
            'status' => ResponsibilityAssignmentStatus::Active,
            'active_fingerprint' => hash('sha256', (string) Str::uuid()),
            'effective_at' => now(),
            'ended_at' => null,
            'assigned_by_user_id' => User::factory(),
            'ended_by_user_id' => null,
            'predecessor_assignment_id' => null,
            'idempotency_key' => (string) Str::uuid(),
            'reason' => 'Test responsibility assignment',
            'notes' => null,
        ];
    }
}
