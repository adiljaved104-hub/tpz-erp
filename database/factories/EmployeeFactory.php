<?php

namespace Database\Factories;

use App\Enums\EmployeeRole;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Employee> */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'employee_id' => 'TPZ-'.$this->faker->unique()->numerify('####'),
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => null,
            'phone' => null,
            'team_id' => null,
            'designation' => 'Staff',
            'role' => EmployeeRole::Staff,
            'status' => true,
            'joining_date' => null,
        ];
    }

    public function role(EmployeeRole $role): static
    {
        return $this->state(fn (): array => ['role' => $role]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => false]);
    }
}
