<?php

namespace Tests\Feature\Invoices;

use App\Enums\EmployeeRole;
use App\Enums\ProductCondition;
use App\Enums\TaxInvoiceTermsProfile;
use App\Filament\Pages\Administration\InvoiceSettings as InvoiceSettingsPage;
use App\Filament\Resources\TaxInvoices\Pages\CreateTaxInvoice;
use App\Filament\Resources\TaxInvoices\Pages\ListTaxInvoices;
use App\Filament\Resources\TaxInvoices\Pages\ViewTaxInvoice;
use App\Models\CompanyProfile;
use App\Models\Employee;
use App\Models\InvoiceSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\TaxInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Invoices\InvoiceSettingsService;
use App\Services\Invoices\TaxInvoiceDocumentService;
use App\Services\Invoices\TaxInvoiceOrderImportService;
use App\Services\Invoices\TaxInvoiceService;
use App\Support\ArabicPdfText;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TaxInvoicePhoneRenewedTermsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', str_repeat('a', 32));
    }

    public function test_additive_nullable_migration_preserves_historical_invoices(): void
    {
        $owner = $this->user();
        $this->settings($owner);
        $invoice = $this->issue($owner);
        $originalTerms = $invoice->terms_en_snapshot;
        $migration = require database_path('migrations/2026_10_07_090000_add_tax_invoice_phone_and_renewed_terms.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('tax_invoices', 'customer_phone'));
        $this->assertFalse(Schema::hasColumn('invoice_settings', 'renewed_terms_en'));
        $migration->up();
        $invoice->refresh();
        $this->assertNull($invoice->customer_phone);
        $this->assertNull($invoice->terms_profile);
        $this->assertNull($invoice->renewed_terms_en_snapshot);
        $this->assertNull($invoice->renewed_terms_ar_snapshot);
        $this->assertSame($originalTerms, $invoice->terms_en_snapshot);
        $this->assertSame(1, TaxInvoice::query()->count());
        $phone = collect(Schema::getColumns('tax_invoices'))->firstWhere('name', 'customer_phone');
        $this->assertTrue($phone['nullable']);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_manual_phone_is_optional_normalized_and_snapshotted(): void
    {
        $owner = $this->user();
        foreach ([null, '', '   ', ' +971 50 123 4567 '] as $phone) {
            $invoice = $this->issue($owner, ['customer_phone' => $phone]);
            $expected = filled($phone) ? trim($phone) : null;
            $this->assertSame($expected, $invoice->fresh()->customer_phone);
            $this->assertDatabaseHas('tax_invoices', ['id' => $invoice->id, 'customer_phone' => $expected]);
        }
        $this->assertNull($this->issue($owner)->customer_phone);
    }

    public function test_phone_and_terms_profile_are_validated_before_issue(): void
    {
        $owner = $this->user();
        foreach ([['customer_phone' => str_repeat('1', 41)], ['terms_profile' => 'unknown']] as $invalid) {
            try {
                $this->issue($owner, $invalid);
                $this->fail('Invalid invoice input was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey(array_key_first($invalid), $exception->errors());
            }
        }
        $this->assertDatabaseCount('tax_invoices', 0);
    }

    public function test_import_prefills_phone_in_the_service_and_livewire_and_saves_reviewed_phone(): void
    {
        $owner = $this->user();
        CompanyProfile::query()->create(['id' => 1, 'company_name_en' => 'Tech Point Zone', 'trn' => '100000000000001', 'updated_by_user_id' => $owner->id]);
        $order = $this->order($owner, [ProductCondition::Renewed]);
        $prefill = app(TaxInvoiceOrderImportService::class)->prefill($owner, $order->id);
        $this->assertSame('+971501234567', $prefill['customer_phone']);
        $component = Livewire::actingAs($owner)->test(CreateTaxInvoice::class)
            ->set('data.source_order_id', $order->id)
            ->assertSet('data.customer_phone', '+971501234567')
            ->assertSet('data.terms_profile', 'auto')
            ->fillForm(['customer_address' => 'Dubai', 'customer_phone' => '+971509876543'])
            ->call('create')->assertHasNoFormErrors();
        $invoice = TaxInvoice::query()->sole();
        $this->assertSame('+971509876543', $invoice->customer_phone);
        $order->update(['customer_phone' => '+971500000000']);
        $this->assertSame('+971509876543', $invoice->fresh()->customer_phone);
        $this->assertSame($order->id, $invoice->source_order_id);
    }

    public function test_customer_phone_is_rendered_in_bill_to_only_when_present_in_print_and_pdf_html(): void
    {
        $owner = $this->user();
        $documents = app(TaxInvoiceDocumentService::class);
        foreach ([null, '+971501234567'] as $phone) {
            $invoice = $this->issue($owner, ['customer_phone' => $phone]);
            foreach (['print', 'pdf'] as $mode) {
                $data = $documents->printViewData($invoice);
                $data['documentMode'] = $mode;
                $html = view('invoices.tax-invoice', $data)->render();
                if ($phone === null) {
                    $this->assertStringNotContainsString('class="customer-phone"', $html);
                } else {
                    $this->assertStringContainsString('Phone: '.$phone, $html);
                    $this->assertLessThan(strpos($html, 'INVOICE DETAILS /'), strpos($html, 'Phone: '.$phone));
                }
            }
            Livewire::actingAs($owner)->test(ViewTaxInvoice::class, ['record' => $invoice->id])
                ->assertSuccessful();
        }
    }

    public function test_phone_correction_is_audited_in_modal_history_and_preserves_terms_and_financials(): void
    {
        $owner = $this->user();
        $this->settings($owner);
        $invoice = $this->issue($owner, ['customer_phone' => '+971501234567', 'terms_profile' => 'renewed']);
        $immutable = $invoice->only(['terms_profile', 'terms_en_snapshot', 'terms_ar_snapshot', 'renewed_terms_en_snapshot', 'renewed_terms_ar_snapshot', 'invoice_number', 'invoice_date', 'grand_total']);
        Livewire::actingAs($owner)->test(ListTaxInvoices::class)
            ->mountTableAction('editCustomerDetails', $invoice)
            ->assertTableActionDataSet(['customer_phone' => '+971501234567'])
            ->setTableActionData([
                'customer_name' => $invoice->customer_name,
                'customer_phone' => '+971509876543',
                'customer_trn' => null,
                'customer_address' => $invoice->customer_address,
                'amendment_reason' => 'Correct the customer contact number.',
            ])->callMountedTableAction()->assertHasNoTableActionErrors();
        $invoice->refresh();
        $this->assertSame('+971509876543', $invoice->customer_phone);
        $this->assertEquals($immutable, $invoice->only(array_keys($immutable)));
        $log = $invoice->customerDetailAmendments()->sole();
        $this->assertSame('+971501234567', $log->properties['previous_customer_phone']);
        $this->assertSame('+971509876543', $log->properties['new_customer_phone']);
        $this->assertSame($owner->id, $log->properties['actor_id']);
        $html = view('filament.resources.tax-invoices.partials.customer-amendment-history', ['amendments' => $invoice->customerDetailAmendments()->with('actor')->get()])->render();
        $this->assertStringContainsString('+971501234567', $html);
        $this->assertStringContainsString('+971509876543', $html);
        $this->actingAs($owner)->get(route('tax-invoices.pdf', ['invoice' => $invoice, 'print' => 1]))
            ->assertOk()->assertSee('Phone: +971509876543');

        $data = ['customer_name' => $invoice->customer_name, 'customer_address' => $invoice->customer_address, 'amendment_reason' => 'Update details without replacing the phone.'];
        app(TaxInvoiceService::class)->updateCustomerDetails($invoice, $data, $owner);
        $this->assertSame('+971509876543', $invoice->refresh()->customer_phone);
        $data['customer_phone'] = null;
        app(TaxInvoiceService::class)->updateCustomerDetails($invoice, $data, $owner);
        $this->assertNull($invoice->refresh()->customer_phone);
        $this->assertNull($invoice->customerDetailAmendments()->first()->properties['new_customer_phone']);
    }

    public function test_normal_search_covers_all_requested_fields_and_created_by_filter_still_works(): void
    {
        $owner = $this->user();
        $creator = $this->user(EmployeeRole::Staff, 'Invoice Creator Needle');
        $invoice = $this->issue($creator, ['customer_name' => 'Customer Name Needle', 'customer_phone' => '+971501234567', 'customer_trn' => 'TRN-NEEDLE', 'order_reference' => 'ORDER-NEEDLE']);
        $other = $this->issue($owner);
        foreach ([$invoice->invoice_number, 'ORDER-NEEDLE', 'Customer Name Needle', 'TRN-NEEDLE', '+971501234567', 'Invoice Creator Needle'] as $search) {
            Livewire::actingAs($owner)->test(ListTaxInvoices::class)
                ->searchTable($search)->assertCanSeeTableRecords([$invoice])->assertCanNotSeeTableRecords([$other]);
        }
        Livewire::actingAs($owner)->test(ListTaxInvoices::class)
            ->filterTable('created_by_user_id', $creator->id)
            ->assertCanSeeTableRecords([$invoice])->assertCanNotSeeTableRecords([$other]);
        $creator->update(['name' => 'Renamed Creator']);
        Livewire::actingAs($owner)->test(ListTaxInvoices::class)
            ->searchTable('Renamed Creator')->assertCanSeeTableRecords([$invoice])->assertCanNotSeeTableRecords([$other]);
    }

    public function test_search_cannot_bypass_existing_invoice_scope(): void
    {
        $staff = $this->user(EmployeeRole::Staff, 'Scoped Staff');
        $other = $this->user(EmployeeRole::Staff, 'Hidden Creator');
        $mine = $this->issue($staff, ['customer_name' => 'Shared Name', 'customer_phone' => '+971501234567']);
        $hidden = $this->issue($other, ['customer_name' => 'Shared Name', 'customer_phone' => '+971501234567']);
        foreach (['Shared Name', '+971501234567', 'Hidden Creator', $hidden->invoice_number] as $search) {
            $component = Livewire::actingAs($staff)->test(ListTaxInvoices::class)->searchTable($search)
                ->assertCanNotSeeTableRecords([$hidden]);
            if (in_array($search, ['Shared Name', '+971501234567'], true)) {
                $component->assertCanSeeTableRecords([$mine]);
            }
        }
        Livewire::actingAs($staff)->test(ListTaxInvoices::class)
            ->filterTable('created_by_user_id', $other->id)->assertCanNotSeeTableRecords([$hidden, $mine]);
        $this->actingAs($staff)->get(route('tax-invoices.pdf', ['invoice' => $hidden, 'print' => 1]))->assertForbidden();
    }

    #[DataProvider('termsCases')]
    public function test_terms_profiles_resolve_from_product_condition_and_snapshot_once(?array $conditions, string $profile, bool $renewed): void
    {
        $owner = $this->user();
        $this->settings($owner);
        $standard = ! $renewed;
        $data = ['terms_profile' => $profile];
        if ($conditions !== null) {
            $order = $this->order($owner, $conditions);
            $data['source_order_id'] = $order->id;
        }
        $invoice = $this->issue($owner, $data);
        $this->assertSame(TaxInvoiceTermsProfile::from($profile), $invoice->terms_profile);
        $this->assertSame($standard ? 'Standard invoice terms.' : '', $invoice->terms_en_snapshot);
        $this->assertSame($standard ? 'الشروط العادية.' : null, $invoice->terms_ar_snapshot);
        $this->assertSame($renewed ? 'Configured renewed product terms.' : null, $invoice->renewed_terms_en_snapshot);
        $this->assertSame($renewed ? 'شروط المنتجات المجددة.' : null, $invoice->renewed_terms_ar_snapshot);
        $documents = app(TaxInvoiceDocumentService::class);
        $html = view('invoices.tax-invoice', $documents->printViewData($invoice))->render();
        $this->assertSame($standard ? 1 : 0, substr_count($html, 'Standard invoice terms.'));
        $this->assertSame($renewed ? 1 : 0, substr_count($html, 'Configured renewed product terms.'));
        $this->assertSame(1, substr_count($html, 'Terms &amp; Conditions'));
        $this->assertStringNotContainsString('Renewed Product Terms', $html);
        InvoiceSetting::query()->whereKey(1)->update(['terms_en' => 'Later standard terms', 'terms_ar' => 'نص آخر', 'renewed_terms_en' => 'Later renewed terms', 'renewed_terms_ar' => 'نص مجدد آخر']);
        if (isset($order)) {
            Product::query()->whereIn('id', $order->items->pluck('product_id'))->update(['condition' => ProductCondition::Used]);
        }
        $this->assertSame($standard ? 'Standard invoice terms.' : '', $invoice->fresh()->terms_en_snapshot);
        $this->assertSame($renewed ? 'Configured renewed product terms.' : null, $invoice->fresh()->renewed_terms_en_snapshot);
        $this->assertSame($html, view('invoices.tax-invoice', $documents->printViewData($invoice->fresh()))->render());
    }

    public static function termsCases(): array
    {
        return [
            'standard order' => [[ProductCondition::New], 'auto', false],
            'renewed order' => [[ProductCondition::Renewed], 'auto', true],
            'mixed order' => [[ProductCondition::New, ProductCondition::Renewed, ProductCondition::Renewed], 'auto', true],
            'other conditions despite renewed title' => [[ProductCondition::Used, ProductCondition::Refurbished, ProductCondition::OpenBox], 'auto', false],
            'manual auto' => [null, 'auto', false],
            'manual standard' => [null, 'standard', false],
            'manual renewed' => [null, 'renewed', true],
            'standard override on renewed order' => [[ProductCondition::Renewed], 'standard', false],
            'renewed override on new order' => [[ProductCondition::New], 'renewed', true],
        ];
    }

    public function test_import_eager_loads_product_conditions_with_constant_query_count(): void
    {
        $owner = $this->user();
        $one = $this->order($owner, [ProductCondition::New]);
        $many = $this->order($owner, array_fill(0, 12, ProductCondition::Renewed));
        $import = app(TaxInvoiceOrderImportService::class);
        $import->authorizedOrder($owner, $one->id);
        $counts = [];
        foreach ([$one, $many] as $order) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $loaded = $import->authorizedOrder($owner, $order->id);
            foreach ($loaded->items as $item) {
                $this->assertTrue($item->relationLoaded('product'));
                $this->assertInstanceOf(ProductCondition::class, $item->product->condition);
            }
            $counts[] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }
        $this->assertSame($counts[0], $counts[1]);
    }

    public function test_renewed_settings_ui_saves_both_languages_with_existing_permissions_and_validation(): void
    {
        $owner = $this->user();
        $this->settings($owner);
        Livewire::actingAs($owner)->test(InvoiceSettingsPage::class)
            ->assertSee('Renewed Terms &amp; Conditions', false)
            ->assertSet('renewedTermsEn', 'Configured renewed product terms.')
            ->set('renewedTermsEn', 'Updated renewed English terms.')
            ->set('renewedTermsAr', 'شروط مجددة محدثة.')
            ->call('save')->assertHasNoErrors();
        $this->assertSame('Updated renewed English terms.', InvoiceSetting::query()->sole()->renewed_terms_en);
        $this->assertSame('شروط مجددة محدثة.', InvoiceSetting::query()->sole()->renewed_terms_ar);
        Livewire::actingAs($owner)->test(InvoiceSettingsPage::class)
            ->set('renewedTermsEn', str_repeat('x', 10001))->call('save')->assertHasErrors(['renewedTermsEn']);
        $staff = $this->user(EmployeeRole::Staff);
        $this->actingAs($staff)->get(InvoiceSettingsPage::getUrl())->assertForbidden();
        try {
            app(InvoiceSettingsService::class)->save(['renewed_terms_en' => 'Unauthorized'], $staff);
            $this->fail('Staff changed renewed settings.');
        } catch (AuthorizationException) {
            $this->assertSame('Updated renewed English terms.', InvoiceSetting::query()->sole()->renewed_terms_en);
        }
        $admin = $this->user(EmployeeRole::Admin);
        Livewire::actingAs($admin)->test(InvoiceSettingsPage::class)->assertSuccessful();
    }

    #[DataProvider('immutableFields')]
    public function test_issued_phone_profile_and_terms_cannot_be_edited_directly(string $field, string $value): void
    {
        $invoice = $this->issue($this->user());
        $this->expectException(LogicException::class);
        $invoice->update([$field => $value]);
    }

    public static function immutableFields(): array
    {
        return [
            ['customer_phone', '+971500000000'],
            ['terms_profile', 'renewed'],
            ['terms_en_snapshot', 'Altered standard terms'],
            ['renewed_terms_en_snapshot', 'Altered renewed terms'],
            ['renewed_terms_ar_snapshot', 'شروط معدلة'],
        ];
    }

    public function test_renewed_three_item_pdf_stays_one_page_with_connected_arabic(): void
    {
        $owner = $this->user();
        $this->settings($owner);
        $invoice = $this->issue($owner, ['terms_profile' => 'renewed', 'customer_phone' => '+971501234567', 'items' => array_fill(0, 3, ['description' => 'Normal laptop product', 'quantity' => 1, 'unit_price_including_vat' => '105.00'])]);
        $documents = app(TaxInvoiceDocumentService::class);
        $data = $documents->printViewData($invoice);
        $data['documentMode'] = 'pdf';
        $this->assertStringContainsString(app(ArabicPdfText::class)->forDompdf('شروط المنتجات المجددة.'), view('invoices.tax-invoice', $data)->render());
        $pdf = $documents->pdf($invoice)->output();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(1, preg_match_all('/\/Type\s*\/Page(?!s)/', $pdf));
    }

    public function test_long_renewed_terms_use_full_width_without_an_extra_footer_page(): void
    {
        $owner = $this->user();
        $this->settings($owner);
        InvoiceSetting::query()->whereKey(1)->update([
            'terms_en' => InvoiceSettingsService::TERMS_EN,
            'terms_ar' => InvoiceSettingsService::TERMS_AR,
            'renewed_terms_en' => implode("\n", array_map(fn ($line): string => "Sample renewed condition {$line}. Product condition and coverage follow the configured business policy.", range(1, 15))),
        ]);
        $invoice = $this->issue($owner, ['terms_profile' => 'renewed', 'items' => array_fill(0, 3, ['description' => 'Laptop product', 'quantity' => 1, 'unit_price_including_vat' => '105.00'])]);
        $documents = app(TaxInvoiceDocumentService::class);
        $html = view('invoices.tax-invoice', $documents->printViewData($invoice))->render();
        $this->assertStringContainsString('class="extended-terms"', $html);
        $this->assertStringNotContainsString('class="footer-spacer"', $html);
        $this->assertSame(1, substr_count($html, 'Terms &amp; Conditions'));
        $this->assertStringNotContainsString('Renewed Product Terms', $html);
        $this->assertSame(1, preg_match_all('/\/Type\s*\/Page(?!s)/', $documents->pdf($invoice)->output()));
    }

    private function issue(User $actor, array $overrides = []): TaxInvoice
    {
        CompanyProfile::query()->firstOrCreate(['id' => 1], ['company_name_en' => 'Tech Point Zone', 'company_name_ar' => 'تك بوينت زون', 'trn' => '100000000000001', 'address_en' => 'Dubai UAE', 'updated_by_user_id' => $actor->id]);

        return app(TaxInvoiceService::class)->create(array_replace([
            'customer_name' => 'Manual Customer', 'customer_address' => 'Dubai',
            'order_reference' => 'MANUAL-'.Str::uuid(), 'invoice_date' => today()->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['description' => 'Laptop', 'quantity' => 1, 'unit_price_including_vat' => '105.00']],
        ], $overrides), $actor);
    }

    private function settings(User $owner): void
    {
        InvoiceSetting::query()->create([
            'id' => 1, 'invoice_prefix' => 'TP-INV', 'starting_number' => 9153, 'vat_rate' => '5.00',
            'terms_en' => 'Standard invoice terms.', 'terms_ar' => 'الشروط العادية.',
            'renewed_terms_en' => 'Configured renewed product terms.', 'renewed_terms_ar' => 'شروط المنتجات المجددة.',
            'updated_by_user_id' => $owner->id,
        ]);
    }

    private function order(User $owner, array $conditions): Order
    {
        $order = Order::query()->create([
            'reference' => 'SO-'.Str::uuid(), 'source' => 'manual', 'status' => 'draft',
            'warehouse_id' => Warehouse::factory()->create()->id,
            'order_date' => today()->toDateString(), 'customer_name' => 'Order Customer',
            'customer_phone' => '+971501234567', 'customer_address' => 'Dubai',
            'subtotal' => '100.00', 'discount_total' => '0.00', 'vat_total' => '5.00', 'grand_total' => '105.00',
            'idempotency_key' => (string) Str::uuid(), 'created_by_user_id' => $owner->id,
        ]);
        foreach ($conditions as $index => $condition) {
            $product = Product::factory()->create(['condition' => $condition, 'name' => 'Renewed in title must not determine terms']);
            OrderItem::query()->create([
                'order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name,
                'sku' => $product->sku, 'ordered_quantity' => 1, 'selling_price' => '100.00',
                'discount_total' => '0.00', 'vat_rate' => '5.0000', 'vat_amount' => '5.00', 'line_total' => '105.00', 'line_number' => $index + 1,
            ]);
        }

        return $order;
    }

    private function user(EmployeeRole $role = EmployeeRole::Owner, ?string $name = null): User
    {
        $user = User::factory()->create($name === null ? [] : ['name' => $name]);
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'status' => true]);

        return $user->refresh();
    }
}
