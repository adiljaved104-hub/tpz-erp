<?php

namespace Tests\Feature\Invoices;

use App\Enums\EmployeeRole;
use App\Enums\OrderStatus;
use App\Filament\Pages\Administration\InvoiceSettings as InvoiceSettingsPage;
use App\Filament\Resources\TaxInvoices\Pages\CreateTaxInvoice;
use App\Models\CompanyProfile;
use App\Models\Employee;
use App\Models\InvoiceSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemUpgradeSelection;
use App\Models\Product;
use App\Models\SalesConfiguration;
use App\Models\TaxInvoice;
use App\Models\UpgradeRecipe;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Invoices\InvoiceSettingsService;
use App\Services\Invoices\TaxInvoiceOrderImportService;
use App\Services\Invoices\TaxInvoiceService;
use App\Services\Quotations\QuotationService;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class TaxInvoiceOrderImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_search_prefills_customer_facing_lines_and_excludes_cancelled_or_unauthorized_orders(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        [$order, $item] = $this->order($owner);
        [$cancelled] = $this->order($owner, OrderStatus::Cancelled, 'SO-CANCELLED-101', 'EXT-CANCELLED-101');
        $import = app(TaxInvoiceOrderImportService::class);

        $this->assertArrayHasKey($order->id, $import->options($owner, 'SO-IMPORT-101'));
        $this->assertArrayHasKey($order->id, $import->options($owner, 'EXT-IMPORT-101'));
        $this->assertArrayNotHasKey($cancelled->id, $import->options($owner, 'CANCELLED'));
        $this->assertNull($import->prefill($owner, $cancelled->id));
        $this->assertArrayNotHasKey($order->id, $import->options($staff, 'SO-IMPORT-101'));
        $this->assertNull($import->prefill($staff, $order->id));

        $prefill = $import->prefill($owner, $order->id);
        $this->assertSame($order->reference, $prefill['order_reference']);
        $this->assertSame('EXT-IMPORT-101', $prefill['external_order_number']);
        $this->assertSame('Order Customer', $prefill['customer_name']);
        $this->assertSame($item->id, $prefill['items'][0]['source_order_item_id']);
        $this->assertSame($item->product_name, $prefill['items'][0]['description']);
        $this->assertSame(3, $prefill['items'][0]['quantity']);
        $this->assertSame('105.00', $prefill['items'][0]['unit_price_including_vat']);
        $this->assertArrayNotHasKey('cost_price', $prefill['items'][0]);
        $this->assertArrayNotHasKey('cogs', $prefill['items'][0]);
        $this->assertArrayNotHasKey('average_cost', $item->getAttributes());
    }

    public function test_manual_and_partial_imported_invoices_preserve_snapshot_and_allow_reusing_order(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->profile($owner);
        [$order, $item] = $this->order($owner);
        $manual = app(TaxInvoiceService::class)->create($this->invoiceData(), $owner);
        $this->assertNull($manual->source_order_id);
        $this->assertNull($manual->items->sole()->source_order_item_id);

        $prefill = app(TaxInvoiceOrderImportService::class)->prefill($owner, $order->id);
        $data = $this->invoiceData() + [];
        $data['source_order_id'] = $order->id;
        $data['order_reference'] = $prefill['order_reference'];
        $data['customer_name'] = $prefill['customer_name'];
        $data['items'] = $prefill['items'];
        $data['items'][0]['quantity'] = 1;
        $data['items'][0]['description'] = 'Customer-approved partial item';
        $first = app(TaxInvoiceService::class)->create($data, $owner);
        $this->assertSame($order->id, $first->source_order_id);
        $this->assertSame($item->id, $first->items->sole()->source_order_item_id);
        $this->assertSame(1, $first->items->sole()->quantity);
        $this->assertSame('Customer-approved partial item', $first->items->sole()->description);

        $data['idempotency_key'] = (string) Str::uuid();
        $data['items'][0]['quantity'] = 2;
        $second = app(TaxInvoiceService::class)->create($data, $owner);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($order->id, $second->source_order_id);
        $this->assertSame($item->id, $second->items->sole()->source_order_item_id);
        $this->assertDatabaseCount('tax_invoices', 3);

        $order->update(['customer_name' => 'Later Order Customer']);
        $this->assertSame('Order Customer', $first->refresh()->customer_name);
        $this->assertSame('Customer-approved partial item', $first->items->sole()->description);
        $retry = $this->invoiceData();
        $retry['idempotency_key'] = $first->idempotency_key;
        $this->assertSame($first->id, app(TaxInvoiceService::class)->create($retry, $owner)->id);
    }

    public function test_source_link_is_server_validated_before_invoice_is_created(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->profile($owner);
        [$order] = $this->order($owner);
        [$other, $otherItem] = $this->order($owner, OrderStatus::Draft, 'SO-OTHER-101', 'EXT-OTHER-101');
        $data = $this->invoiceData();
        $data['source_order_id'] = $order->id;
        $data['items'][0]['source_order_item_id'] = $otherItem->id;

        try {
            app(TaxInvoiceService::class)->create($data, $owner);
            $this->fail('An item from another Order must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items.0.source_order_item_id', $exception->errors());
        }
        $this->assertDatabaseCount('tax_invoices', 0);
        $this->assertDatabaseCount('tax_invoice_items', 0);
        $this->assertSame($other->id, $otherItem->order_id);
    }

    public function test_upgraded_order_import_uses_the_customer_description_and_omits_internal_cost_columns(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        [$order, $item] = $this->order($owner);
        $configuration = SalesConfiguration::query()->create([
            'product_id' => $item->product_id, 'hardware_profile_version' => 1,
            'display_name' => '16GB RAM / 512GB SSD', 'target_ram_mb' => 16384,
            'suggested_selling_addon' => '100.00',
            'active' => true, 'created_by_user_id' => $owner->id, 'updated_by_user_id' => $owner->id,
        ]);
        $recipe = UpgradeRecipe::query()->create([
            'sales_configuration_id' => $configuration->id, 'hardware_profile_version' => 1,
            'name' => 'Customer configuration', 'preferred' => true, 'priority' => 1,
            'labour_unit_cost' => '20.0000', 'active' => true,
            'created_by_user_id' => $owner->id, 'updated_by_user_id' => $owner->id,
        ]);
        OrderItemUpgradeSelection::query()->create([
            'order_item_id' => $item->id, 'sales_configuration_id' => $configuration->id,
            'upgrade_recipe_id' => $recipe->id, 'hardware_profile_version' => 1,
            'configuration_snapshot' => ['display_name' => '16GB RAM / 512GB SSD'],
            'recipe_snapshot' => [], 'suggested_selling_addon_snapshot' => '100.00',
            'labour_cost_snapshot' => '20.0000', 'recovery_snapshot' => [],
            'selected_by_user_id' => $owner->id,
        ]);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $prefill = app(TaxInvoiceOrderImportService::class)->prefill($owner, $order->id);
        $this->assertSame($item->product_name."\n16GB RAM / 512GB SSD", $prefill['items'][0]['description']);
        $itemQuery = collect($queries)->first(fn (string $sql): bool => str_contains($sql, 'from "order_items"'));
        $this->assertNotNull($itemQuery);
        $this->assertStringNotContainsString('cost_price', $itemQuery);
        $this->assertStringNotContainsString('average_cost', $itemQuery);
        $this->assertStringNotContainsString('cogs', $itemQuery);
        $this->assertStringNotContainsString('labour_cost', $itemQuery);
        $this->assertArrayNotHasKey('labour_cost_snapshot', $prefill['items'][0]);
    }

    public function test_create_page_order_selector_uses_scoped_server_search(): void
    {
        config()->set('app.key', str_repeat('a', 32));
        $owner = $this->user(EmployeeRole::Owner);
        [$order] = $this->order($owner);
        [$otherOrder, $otherItem] = $this->order($owner, OrderStatus::Draft, 'SO-OTHER-UI', 'EXT-OTHER-UI');
        [$cancelled] = $this->order($owner, OrderStatus::Cancelled, 'SO-CANCELLED-UI', 'EXT-CANCELLED-UI');

        $component = Livewire::actingAs($owner)->test(CreateTaxInvoice::class);
        $field = collect($component->instance()->form->getFlatFields(withHidden: true))
            ->first(fn ($field): bool => $field->getName() === 'source_order_id');
        $this->assertInstanceOf(Select::class, $field);
        $this->assertArrayHasKey($order->id, $field->getSearchResults('EXT-IMPORT-101'));
        $this->assertArrayNotHasKey($cancelled->id, $field->getSearchResults('CANCELLED-UI'));

        $component->set('data.source_order_id', $order->id)
            ->assertSet('data.order_reference', $order->reference)
            ->assertSet('data.customer_name', 'Order Customer');
        $importedItems = collect($component->get('data.items'))->values();
        $this->assertCount(1, $importedItems);
        $this->assertSame($order->items()->firstOrFail()->id, (int) $importedItems->first()['source_order_item_id']);

        $component->set('data.source_order_id', $otherOrder->id)
            ->assertSet('data.order_reference', $otherOrder->reference);
        $replacementItems = collect($component->get('data.items'))->values();
        $this->assertSame($otherItem->id, (int) $replacementItems->first()['source_order_item_id']);

        $component->set('data.source_order_id', null)->assertSet('data.source_order_id', null);
        $manualItems = collect($component->get('data.items'))->values();
        $this->assertArrayNotHasKey('source_order_item_id', $manualItems->first());
    }

    public function test_document_terms_are_separate_and_issued_invoice_snapshot_is_unchanged(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->profile($owner);
        $settings = app(InvoiceSettingsService::class);
        $nextNumber = $settings->nextInvoiceNumber($settings->settings());
        $settings->save([
            'invoice_prefix' => 'TP-INV', 'next_invoice_number' => $nextNumber,
            'expected_next_invoice_number' => $nextNumber, 'vat_rate' => '5.00',
            'terms_en' => 'Invoice-specific terms', 'terms_ar' => 'شروط الفاتورة',
            'quotation_terms_en' => 'Quotation-specific terms', 'quotation_terms_ar' => 'شروط العرض',
            'proforma_terms_en' => 'Proforma-specific terms', 'proforma_terms_ar' => 'شروط المبدئية',
        ], $owner);
        $invoice = app(TaxInvoiceService::class)->create($this->invoiceData(), $owner);
        $this->assertSame('Invoice-specific terms', $invoice->terms_en_snapshot);

        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $quoteData = [
            'warehouse_id' => $warehouse->id, 'document_type' => 'quotation',
            'quotation_date' => today()->toDateString(), 'valid_until' => today()->addDays(14)->toDateString(),
            'customer_name' => 'Acme', 'customer_address' => 'Dubai', 'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'description' => $product->name, 'quantity' => 1, 'unit_price_including_vat' => '105.00', 'discount_amount' => '0.00', 'vat_rate' => '5.0000']],
        ];
        $quote = app(QuotationService::class)->create($quoteData, $owner);
        $quoteData['document_type'] = 'proforma_invoice';
        $quoteData['idempotency_key'] = (string) Str::uuid();
        $proforma = app(QuotationService::class)->create($quoteData, $owner);
        $this->assertSame('Quotation-specific terms', $quote->terms_en_snapshot);
        $this->assertSame('شروط العرض', $quote->terms_ar_snapshot);
        $this->assertSame('Proforma-specific terms', $proforma->terms_en_snapshot);
        $this->assertSame('شروط المبدئية', $proforma->terms_ar_snapshot);

        $nextNumber = $settings->nextInvoiceNumber();
        $settings->save(['next_invoice_number' => $nextNumber, 'expected_next_invoice_number' => $nextNumber, 'terms_en' => 'Changed invoice terms', 'quotation_terms_en' => 'Changed quotation terms', 'proforma_terms_en' => 'Changed proforma terms'], $owner);
        $this->assertSame('Invoice-specific terms', $invoice->refresh()->terms_en_snapshot);
        $this->assertSame('Quotation-specific terms', $quote->refresh()->terms_en_snapshot);
        $this->assertSame('Proforma-specific terms', $proforma->refresh()->terms_en_snapshot);
    }

    public function test_migration_backfills_prior_terms_and_rolls_back_on_disposable_database(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->profile($owner);
        $invoice = app(TaxInvoiceService::class)->create($this->invoiceData(), $owner);
        $migration = require database_path('migrations/2026_09_21_090000_add_tax_invoice_order_sources_and_document_terms.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('tax_invoices', 'source_order_id'));
        $this->assertFalse(Schema::hasColumn('invoice_settings', 'quotation_terms_en'));
        InvoiceSetting::query()->updateOrCreate(['id' => 1], ['invoice_prefix' => 'TP-INV', 'starting_number' => 9153, 'vat_rate' => '5.00', 'terms_en' => 'Original terms', 'terms_ar' => 'الشروط الأصلية', 'updated_by_user_id' => $owner->id]);
        $migration->up();
        $this->assertTrue(Schema::hasColumn('tax_invoices', 'source_order_id'));
        $this->assertTrue(Schema::hasColumn('tax_invoice_items', 'source_order_item_id'));
        $this->assertSame('Original terms', DB::table('invoice_settings')->value('quotation_terms_en'));
        $this->assertSame('الشروط الأصلية', DB::table('invoice_settings')->value('proforma_terms_ar'));
        $this->assertSame($invoice->id, TaxInvoice::query()->sole()->id);
        $this->assertNull(TaxInvoice::query()->sole()->source_order_id);
        $this->assertNull(TaxInvoice::query()->sole()->items->sole()->source_order_item_id);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_invoice_settings_remain_owner_authorized(): void
    {
        config()->set('app.key', str_repeat('a', 32));
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $this->actingAs($staff)->get(InvoiceSettingsPage::getUrl())->assertForbidden();
        Livewire::actingAs($owner)->test(InvoiceSettingsPage::class)
            ->assertSee('Tax Invoice Terms')
            ->assertSee('Quotation Terms')
            ->assertSee('Proforma Invoice Terms');
    }

    private function invoiceData(): array
    {
        return [
            'customer_name' => 'Manual Customer', 'customer_address' => 'Dubai', 'customer_trn' => null,
            'order_reference' => 'MANUAL-101', 'invoice_date' => today()->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['description' => 'Manual laptop', 'quantity' => 1, 'unit_price_including_vat' => '105.00']],
        ];
    }

    private function order(User $actor, OrderStatus $status = OrderStatus::Draft, string $reference = 'SO-IMPORT-101', string $external = 'EXT-IMPORT-101'): array
    {
        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $order = Order::query()->create([
            'reference' => $reference, 'source' => 'manual', 'status' => $status, 'warehouse_id' => $warehouse->id,
            'external_order_number' => $external, 'order_date' => today()->toDateString(),
            'customer_name' => 'Order Customer', 'customer_phone' => '+971500000000',
            'subtotal' => '300.00', 'discount_total' => '0.00', 'vat_total' => '15.00', 'grand_total' => '315.00',
            'idempotency_key' => (string) Str::uuid(), 'created_by_user_id' => $actor->id,
        ]);
        $item = OrderItem::query()->create([
            'order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name,
            'sku' => $product->sku, 'ordered_quantity' => 3, 'selling_price' => '100.00',
            'discount_total' => '0.00', 'vat_rate' => '5.0000', 'vat_amount' => '15.00', 'line_total' => '315.00',
        ]);

        return [$order, $item];
    }

    private function profile(User $owner): void
    {
        CompanyProfile::query()->create(['id' => 1, 'company_name_en' => 'Tech Point Zone', 'company_name_ar' => 'تك بوينت زون', 'trn' => '100000000000001', 'address_en' => 'Dubai UAE', 'legal_statement_en' => 'Registered company.', 'updated_by_user_id' => $owner->id]);
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'status' => true]);

        return $user->refresh();
    }
}
