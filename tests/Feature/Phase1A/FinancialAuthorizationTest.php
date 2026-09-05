<?php

namespace Tests\Feature\Phase1A;

use App\Enums\EmployeeRole;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Employee;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class FinancialAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_financial_gate_is_owner_only_and_product_query_omits_cost_for_others(): void
    {
        Product::factory()->create(['cost_price' => '123.4500']);

        $admin = $this->userWithRole(EmployeeRole::Admin);
        $this->actingAs($admin);
        $this->assertFalse(Gate::allows('viewFinancialData'));
        $this->assertArrayNotHasKey('cost_price', ProductResource::getEloquentQuery()->firstOrFail()->getAttributes());

        $owner = $this->userWithRole(EmployeeRole::Owner);
        $this->actingAs($owner);
        $this->assertTrue(Gate::allows('viewFinancialData'));
        $this->assertArrayHasKey('cost_price', ProductResource::getEloquentQuery()->firstOrFail()->getAttributes());
    }

    private function userWithRole(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
