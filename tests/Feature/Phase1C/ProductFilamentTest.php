<?php

namespace Tests\Feature\Phase1C;

use App\Enums\EmployeeRole;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Employee;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductFilamentTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_and_admin_forms_apply_financial_field_permissions(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->actingAs($owner)->get('/admin/products/create')
            ->assertOk()
            ->assertSee('Cost Price')
            ->assertSee('Selling Price')
            ->assertDontSee('Average Cost');

        $admin = $this->user(EmployeeRole::Admin);
        $this->actingAs($admin)->get('/admin/products/create')
            ->assertOk()
            ->assertDontSee('Cost Price')
            ->assertSee('Selling Price');
    }

    public function test_manager_is_read_only_and_staff_has_no_resource_access(): void
    {
        Product::factory()->create();
        $manager = $this->user(EmployeeRole::Manager);
        $this->actingAs($manager)->get('/admin/products')->assertOk()->assertSee('Selling Price');
        $this->actingAs($manager)->get('/admin/products/create')->assertForbidden();

        $staff = $this->user(EmployeeRole::Staff);
        $this->actingAs($staff)->get('/admin/products')->assertForbidden();
    }

    public function test_product_pages_do_not_expose_delete_actions(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $product = Product::factory()->create();

        $this->actingAs($owner)->get("/admin/products/{$product->id}/edit")
            ->assertOk()
            ->assertDontSee('Delete');
    }

    public function test_authorized_users_can_identify_active_products_with_missing_or_zero_selling_prices(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $missing = Product::factory()->create(['status' => 'active', 'selling_price' => '0.00']);
        $ready = Product::factory()->create(['status' => 'active', 'selling_price' => '100.00']);

        Livewire::actingAs($owner)->test(ListProducts::class)
            ->assertTableColumnExists('price_readiness')
            ->assertTableFilterExists('missing_selling_price')
            ->assertSee('Missing / Zero')
            ->filterTable('missing_selling_price')
            ->assertCanSeeTableRecords([$missing])
            ->assertCanNotSeeTableRecords([$ready]);

        $this->assertSame('0.00', $missing->fresh()->selling_price);
        $this->assertSame('100.00', $ready->fresh()->selling_price);
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create([
            'email' => fake()->unique()->userName().'@techpointzone.com',
        ]);
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
