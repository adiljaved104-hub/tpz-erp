<?php

namespace Tests\Feature\Catalog;

use App\Enums\EmployeeRole;
use App\Filament\Resources\ProductBrands\Pages\ListProductBrands;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Models\Employee;
use App\Models\ProductBrand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CatalogFilamentTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_resources_without_delete_ui_and_product_has_inline_catalog_creation(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->actingAs($owner)->get('/admin/product-brands')->assertOk()->assertDontSee('Delete selected');
        $this->actingAs($owner)->get('/admin/product-categories')->assertOk()->assertDontSee('Delete selected');

        $component = Livewire::test(CreateProduct::class);
        $fields = collect($component->instance()->getSchema('form')->getFlatFields());
        $this->assertTrue($fields->first(fn ($field): bool => $field->getName() === 'brand_id')->getCreateOptionAction()->isVisible());
        $this->assertTrue($fields->first(fn ($field): bool => $field->getName() === 'category_id')->getCreateOptionAction()->isVisible());
    }

    public function test_manager_is_read_only_and_staff_is_denied(): void
    {
        $manager = $this->user(EmployeeRole::Manager);
        $this->actingAs($manager)->get('/admin/product-brands')->assertOk();
        $this->actingAs($manager)->get('/admin/product-categories')->assertOk();
        $this->actingAs($manager)->get('/admin/product-brands/create')->assertForbidden();
        $this->actingAs($manager)->get('/admin/product-categories/create')->assertForbidden();

        $staff = $this->user(EmployeeRole::Staff);
        $this->actingAs($staff)->get('/admin/product-brands')->assertForbidden();
        $this->actingAs($staff)->get('/admin/product-categories')->assertForbidden();
    }

    public function test_brand_list_searches_and_filters_active_status(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $active = ProductBrand::factory()->create(['name' => 'Searchable Brand', 'normalized_name' => 'searchable brand']);
        $inactive = ProductBrand::factory()->create(['name' => 'Inactive Brand', 'normalized_name' => 'inactive brand', 'status' => false]);
        $this->actingAs($owner);

        Livewire::test(ListProductBrands::class)
            ->assertTableFilterExists('status')
            ->searchTable('Searchable')
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$inactive]);

        Livewire::test(ListProductBrands::class)
            ->filterTable('status', false)
            ->assertCanSeeTableRecords([$inactive])
            ->assertCanNotSeeTableRecords([$active]);
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
