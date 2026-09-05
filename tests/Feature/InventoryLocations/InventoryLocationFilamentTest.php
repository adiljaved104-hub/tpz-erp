<?php

namespace Tests\Feature\InventoryLocations;

use App\Enums\EmployeeRole;
use App\Enums\InventoryLocationType;
use App\Filament\Resources\Warehouses\Pages\CreateWarehouse;
use App\Filament\Resources\Warehouses\Pages\EditWarehouse;
use App\Models\Employee;
use App\Models\MarketplacePlatform;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InventoryLocationFilamentTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_form_reactively_shows_and_clears_marketplace_fields(): void
    {
        $owner = $this->owner();
        $platform = MarketplacePlatform::factory()->create([
            'name' => 'Amazon UAE',
            'code' => 'amazon_uae',
        ]);

        Livewire::actingAs($owner)
            ->test(CreateWarehouse::class)
            ->assertFormFieldIsHidden('marketplace_platform_id')
            ->assertFormFieldIsHidden('fulfillment_tag')
            ->set('data.location_type', InventoryLocationType::MarketplaceFulfilment)
            ->assertFormFieldIsVisible('marketplace_platform_id')
            ->assertFormFieldIsVisible('fulfillment_tag')
            ->set('data.marketplace_platform_id', $platform->getKey())
            ->set('data.fulfillment_tag', 'FBA')
            ->set('data.location_type', InventoryLocationType::CompanyWarehouse)
            ->assertFormFieldIsHidden('marketplace_platform_id')
            ->assertFormFieldIsHidden('fulfillment_tag')
            ->assertSet('data.marketplace_platform_id', null)
            ->assertSet('data.fulfillment_tag', null);
    }

    public function test_create_form_surfaces_platform_validation_and_creates_a_valid_marketplace_location(): void
    {
        $owner = $this->owner();
        $platform = MarketplacePlatform::factory()->create([
            'name' => 'Amazon UAE',
            'code' => 'amazon_uae',
        ]);

        Livewire::actingAs($owner)
            ->test(CreateWarehouse::class)
            ->fillForm([
                'name' => 'Amazon FBA UAE',
                'code' => 'AMZ_FBA_UAE',
                'location_type' => InventoryLocationType::MarketplaceFulfilment,
                'fulfillment_tag' => 'FBA',
            ])
            ->call('create')
            ->assertHasFormErrors(['marketplace_platform_id' => 'required'])
            ->assertFormFieldIsVisible('marketplace_platform_id');

        $this->assertDatabaseMissing('warehouses', ['code' => 'AMZ_FBA_UAE']);

        Livewire::actingAs($owner)
            ->test(CreateWarehouse::class)
            ->fillForm([
                'name' => 'Amazon FBA UAE',
                'code' => 'AMZ_FBA_UAE',
                'location_type' => InventoryLocationType::MarketplaceFulfilment,
                'marketplace_platform_id' => $platform->getKey(),
                'fulfillment_tag' => 'FBA',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('warehouses', [
            'name' => 'Amazon FBA UAE',
            'code' => 'AMZ_FBA_UAE',
            'location_type' => InventoryLocationType::MarketplaceFulfilment->value,
            'marketplace_platform_id' => $platform->getKey(),
            'fulfillment_tag' => 'FBA',
        ]);
    }

    public function test_edit_form_reacts_and_clears_marketplace_metadata_when_type_changes(): void
    {
        $owner = $this->owner();
        $platform = MarketplacePlatform::factory()->create();
        $warehouse = Warehouse::factory()->create([
            'name' => 'Amazon FBA UAE',
            'code' => 'AMZ_FBA_UAE',
            'location_type' => InventoryLocationType::MarketplaceFulfilment,
            'marketplace_platform_id' => $platform->getKey(),
            'fulfillment_tag' => 'FBA',
        ]);

        Livewire::actingAs($owner)
            ->test(EditWarehouse::class, ['record' => $warehouse->getRouteKey()])
            ->assertFormFieldIsVisible('marketplace_platform_id')
            ->assertFormFieldIsVisible('fulfillment_tag')
            ->set('data.location_type', InventoryLocationType::Other)
            ->assertFormFieldIsHidden('marketplace_platform_id')
            ->assertFormFieldIsHidden('fulfillment_tag')
            ->assertSet('data.marketplace_platform_id', null)
            ->assertSet('data.fulfillment_tag', null)
            ->call('save')
            ->assertHasNoFormErrors();

        $warehouse->refresh();

        $this->assertSame(InventoryLocationType::Other, $warehouse->location_type);
        $this->assertNull($warehouse->marketplace_platform_id);
        $this->assertNull($warehouse->fulfillment_tag);
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role(EmployeeRole::Owner)->create([
            'email' => $user->email,
        ]);

        return $user->refresh();
    }
}
