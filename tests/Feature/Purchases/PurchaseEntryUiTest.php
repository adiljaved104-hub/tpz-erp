<?php

namespace Tests\Feature\Purchases;

use App\Enums\EmployeeRole;
use App\Filament\Resources\Purchases\Pages\CreatePurchase;
use App\Filament\Resources\Purchases\Pages\EditPurchase;
use App\Models\Employee;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Purchases\PurchaseFormLineService;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PurchaseEntryUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_fast_entry_form_hides_normal_vat_inputs_and_exposes_bulk_add(): void
    {
        $this->actingAs($this->owner());

        Livewire::test(CreatePurchase::class)
            ->assertSee('Bulk Add Products')
            ->assertSee('Add Product')
            ->assertSee('Quantity')
            ->assertSee('Unit Cost')
            ->assertSee('Line Total')
            ->assertDontSee('VAT %')
            ->assertDontSee('Shipping VAT');
    }

    public function test_product_selection_uses_default_main_warehouse_then_searches_active_products(): void
    {
        $owner = $this->owner();
        $warehouse = Warehouse::factory()->create();
        $active = Product::factory()->create([
            'sku' => 'TPZ-000001',
            'name' => 'Hp 840 g4',
            'brand' => 'HP',
            'model' => '840 G4',
            'status' => 'active',
        ]);
        $discontinued = Product::factory()->create([
            'sku' => 'TPZ-000002',
            'name' => 'hp 430',
            'status' => 'discontinued',
        ]);

        $this->actingAs($owner);
        $component = Livewire::test(CreatePurchase::class)
            ->assertSee('Main Warehouse');
        $productField = collect($component->instance()->form->getFlatFields(withHidden: true))
            ->first(fn ($field): bool => $field->getName() === 'product_id');

        $this->assertInstanceOf(Select::class, $productField);
        $this->assertFalse($productField->isDisabled());

        $component->fillForm(['warehouse_id' => $warehouse->id]);
        $productField = collect($component->instance()->form->getFlatFields(withHidden: true))
            ->first(fn ($field): bool => $field->getName() === 'product_id');
        $options = $productField->getOptions();

        $this->assertFalse($productField->isDisabled());
        $this->assertSame([], $options);
        $this->assertArrayNotHasKey($discontinued->id, $options);
        $this->assertSame([], $productField->getSearchResults('T'));

        foreach (['TPZ-000001', 'Hp 840', 'HP', '840 G4'] as $search) {
            $this->assertArrayHasKey($active->id, $productField->getSearchResults($search));
        }

        $this->assertArrayNotHasKey($discontinued->id, $productField->getSearchResults('TPZ-000002'));
    }

    public function test_bulk_add_uses_the_same_server_searched_active_product_source(): void
    {
        $owner = $this->owner();
        $warehouse = Warehouse::factory()->create();
        $active = Product::factory()->create(['status' => 'active']);
        $discontinued = Product::factory()->create(['status' => 'discontinued']);

        $this->actingAs($owner);
        Livewire::test(CreatePurchase::class)
            ->fillForm(['warehouse_id' => $warehouse->id])
            ->mountFormComponentAction('purchase-items', 'bulkAddProducts')
            ->assertFormFieldExists('product_ids', function (Select $field) use ($active, $discontinued): bool {
                $options = $field->getOptions();
                $results = $field->getSearchResults(substr($active->sku, 0, 2));

                return $options === []
                    && array_key_exists($active->id, $results)
                    && ! array_key_exists($discontinued->id, $field->getSearchResults($discontinued->sku));
            });
    }

    public function test_edit_draft_search_uses_active_products_and_excludes_discontinued_products(): void
    {
        $owner = $this->owner();
        $active = Product::factory()->create(['status' => 'active']);
        $discontinued = Product::factory()->create(['status' => 'discontinued']);
        $purchase = Purchase::factory()->create(['created_by_user_id' => $owner->id]);
        $purchase->items()->create([
            'product_id' => $active->id,
            'ordered_quantity' => 1,
            'received_quantity' => 0,
            'rejected_quantity' => 0,
            'unit_cost' => '10.0000',
            'line_discount_total' => '0.00',
            'inventory_unit_cost' => '10.0000',
            'vat_rate' => '0.00',
            'vat_amount' => '0.00',
            'line_subtotal' => '10.00',
            'line_net' => '10.00',
            'line_total' => '10.00',
        ]);

        $this->actingAs($owner);
        $component = Livewire::test(EditPurchase::class, ['record' => $purchase->getRouteKey()]);
        $productField = collect($component->instance()->form->getFlatFields(withHidden: true))
            ->first(fn ($field): bool => $field->getName() === 'product_id');

        $this->assertInstanceOf(Select::class, $productField);
        $this->assertFalse($productField->isDisabled());
        $this->assertArrayHasKey($active->id, $productField->getSearchResults($active->sku));
        $this->assertArrayNotHasKey($discontinued->id, $productField->getSearchResults($discontinued->sku));
    }

    public function test_create_form_uses_independent_responsive_columns(): void
    {
        $this->actingAs($this->owner());

        $html = Livewire::test(CreatePurchase::class)
            ->assertSee('Purchase')
            ->assertSee('Optional Purchase Details')
            ->assertSee('Purchase Items')
            ->html();

        $this->assertStringContainsString('--cols-default: repeat(1, minmax(0, 1fr))', $html);
        $this->assertStringContainsString('--cols-lg: repeat(2, minmax(0, 1fr))', $html);
        $this->assertStringContainsString('fi-sc-dense', $html);
        $this->assertLessThan(strpos($html, 'Purchase Items'), strpos($html, 'Optional Purchase Details'));
    }

    public function test_edit_form_uses_the_same_independent_responsive_columns(): void
    {
        $owner = $this->owner();
        $purchase = Purchase::factory()->create(['created_by_user_id' => $owner->id]);
        $purchase->items()->create([
            'product_id' => Product::factory()->create()->id,
            'ordered_quantity' => 1,
            'received_quantity' => 0,
            'rejected_quantity' => 0,
            'unit_cost' => '10.0000',
            'line_discount_total' => '0.00',
            'inventory_unit_cost' => '10.0000',
            'vat_rate' => '0.00',
            'vat_amount' => '0.00',
            'line_subtotal' => '10.00',
            'line_net' => '10.00',
            'line_total' => '10.00',
        ]);

        $this->actingAs($owner);
        $html = Livewire::test(EditPurchase::class, ['record' => $purchase->getRouteKey()])
            ->assertSee('Optional Purchase Details')
            ->assertSee('Purchase Items')
            ->html();

        $this->assertStringContainsString('--cols-default: repeat(1, minmax(0, 1fr))', $html);
        $this->assertStringContainsString('--cols-lg: repeat(2, minmax(0, 1fr))', $html);
        $this->assertStringContainsString('fi-sc-dense', $html);
        $this->assertLessThan(strpos($html, 'Purchase Items'), strpos($html, 'Optional Purchase Details'));
    }

    public function test_normal_form_creates_five_product_lines_with_zero_vat(): void
    {
        $owner = $this->owner();
        $supplier = Supplier::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $products = Product::factory()->count(5)->create();

        $this->actingAs($owner);
        Livewire::test(CreatePurchase::class)
            ->fillForm([
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'purchase_date' => now()->toDateString(),
                'supplier_invoice_number' => 'FIVE-LINE-UI',
                'supplier_invoice_date' => now()->toDateString(),
                'shipping_total' => '0.00',
                'other_charges_total' => '0.00',
                'items' => $products->map(fn (Product $product): array => [
                    'product_id' => $product->id,
                    'ordered_quantity' => 1,
                    'unit_cost' => '100.0000',
                ])->all(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $purchase = Purchase::query()->with('items')->sole();
        $this->assertCount(5, $purchase->items);
        $this->assertTrue($purchase->items->every(fn ($item): bool => $item->vat_rate === '0.00' && $item->vat_amount === '0.00'));
        $this->assertSame('500.00', $purchase->net_before_vat);
        $this->assertSame('0.00', $purchase->vat_total);
        $this->assertSame('500.00', $purchase->grand_total);
    }

    public function test_bulk_add_skips_existing_products_and_preserves_manual_costs(): void
    {
        $owner = $this->owner();
        $warehouse = Warehouse::factory()->create();
        $products = Product::factory()->count(5)->create();
        $existing = [
            'existing-line' => [
                'product_id' => $products[0]->id,
                'ordered_quantity' => 2,
                'unit_cost' => '777.0000',
                'unit_cost_touched' => true,
                'line_discount_total' => '0.00',
                'vat_rate' => '0.00',
            ],
        ];

        $lines = app(PurchaseFormLineService::class)->addProducts(
            $existing,
            [...$products->modelKeys(), $products[0]->id],
            $warehouse->id,
            $owner,
        );

        $this->assertCount(5, $lines);
        $this->assertSame('777.0000', $lines['existing-line']['unit_cost']);
        $this->assertSame(1, collect($lines)->where('product_id', $products[0]->id)->count());
        $this->assertTrue(collect($lines)->except('existing-line')->every(
            fn (array $line): bool => $line['ordered_quantity'] === 1
                && $line['unit_cost'] === null
                && $line['vat_rate'] === '0.00',
        ));
    }

    public function test_duplicate_product_is_rejected_by_the_combined_form_workflow(): void
    {
        $owner = $this->owner();
        $supplier = Supplier::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();

        $this->actingAs($owner);
        Livewire::test(CreatePurchase::class)
            ->fillForm([
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'purchase_date' => now()->toDateString(),
                'shipping_total' => '0.00',
                'other_charges_total' => '0.00',
                'items' => [
                    ['product_id' => $product->id, 'ordered_quantity' => 1, 'unit_cost' => '100.0000'],
                    ['product_id' => $product->id, 'ordered_quantity' => 1, 'unit_cost' => '200.0000'],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors();

        $this->assertDatabaseCount('purchases', 0);
        $this->assertDatabaseCount('purchase_items', 0);
    }

    private function owner(): User
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create();

        return $owner->refresh();
    }
}
