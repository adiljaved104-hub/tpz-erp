<?php

namespace Tests\Feature\Invoices;

use App\Enums\EmployeeRole;
use App\Filament\Resources\TaxInvoices\Pages\ViewTaxInvoice;
use App\Models\Employee;
use App\Models\TaxInvoice;
use App\Models\User;
use App\Services\CompanyProfileService;
use App\Services\Invoices\InvoiceVerificationService;
use App\Services\Invoices\TaxInvoiceService;
use App\Support\ArabicPdfText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class TaxInvoiceDocumentParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_confirmed_customer_payment_automatically_uses_invoice_total_and_zero_balance(): void
    {
        $owner = $this->owner();
        app(CompanyProfileService::class)->save($this->profileData(logoPath: null), $owner);
        $invoice = app(TaxInvoiceService::class)->create([
            'customer_name' => 'Paid customer', 'customer_address' => 'Dubai, UAE',
            'order_reference' => 'PAID-ORDER', 'invoice_date' => '2026-11-27',
            'payment_received_in_full' => true, 'idempotency_key' => (string) Str::uuid(),
            'items' => [['description' => 'Laptop', 'quantity' => 2, 'unit_price_including_vat' => '2500.00']],
        ], $owner);

        $this->assertSame('5000.00', $invoice->grand_total);
        $payment = $invoice->fresh()->seller_snapshot['payment_receipt_snapshot'];
        $this->assertSame('confirmed_paid_in_full', $payment['status']);
        $this->assertSame('5000.00', $payment['amount_received_aed']);
        $this->assertSame('0.00', $payment['balance_due_aed']);
        $this->assertSame($owner->id, $payment['confirmed_by_user_id']);

        $this->actingAs($owner)->get(route('tax-invoices.pdf', ['invoice' => $invoice, 'print' => 1]))
            ->assertOk()
            ->assertSee('Payment received')
            ->assertSee('5,000.00')
            ->assertSee('Balance due')
            ->assertSee('0.00')
            ->assertSee('Supply date')
            ->assertSee('27 Nov 2026')
            ->assertDontSee('Full customer payment was confirmed when this invoice was issued.')
            ->assertDontSee('Unit prices include 5.00% VAT');

        $download = $this->actingAs($owner)->get(route('tax-invoices.pdf', ['invoice' => $invoice]));
        $download->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $download->getContent());
    }

    public function test_legacy_and_unconfirmed_invoices_do_not_claim_receipt_or_zero_balance(): void
    {
        $owner = $this->owner();
        app(CompanyProfileService::class)->save($this->profileData(logoPath: null), $owner);
        $invoice = $this->invoice($owner);
        // Simulate a pre-payment-status legacy invoice in this disposable database.
        DB::table('tax_invoice_payment_events')->where('tax_invoice_id', $invoice->id)->delete();

        $this->assertArrayNotHasKey('payment_receipt_snapshot', $invoice->seller_snapshot);
        $this->actingAs($owner)->get(route('tax-invoices.pdf', ['invoice' => $invoice, 'print' => 1]))
            ->assertOk()
            ->assertSee('Payment received')
            ->assertSee('Not recorded')
            ->assertSee('Balance due')
            ->assertSee('Not verified')
            ->assertDontSee('Customer payment is not recorded on this invoice.');
    }

    public function test_new_unpaid_invoice_shows_full_balance_and_default_same_day_supply_date(): void
    {
        $owner = $this->owner();
        app(CompanyProfileService::class)->save($this->profileData(logoPath: null), $owner);
        $invoice = app(TaxInvoiceService::class)->create([
            'customer_name' => 'Unpaid Customer', 'customer_address' => 'Dubai, UAE',
            'order_reference' => 'UNPAID-ORDER', 'invoice_date' => '2026-11-27',
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['description' => 'Laptop', 'quantity' => 1, 'unit_price_including_vat' => '5000.00']],
        ], $owner);

        $this->assertSame('unpaid', $invoice->fresh()->payment_status);
        $event = $invoice->fresh()->latestPaymentEvent;
        $this->assertSame('invoice_issuance', $event->source);
        $this->assertSame('0.00', $event->payment_received_aed);
        $this->assertSame('5000.00', $event->balance_due_aed);
        $this->assertSame('2026-11-27', $invoice->seller_snapshot['supply_date_snapshot']);

        $this->actingAs($owner)->get(route('tax-invoices.pdf', ['invoice' => $invoice, 'print' => 1]))
            ->assertOk()
            ->assertSee('Supply date')
            ->assertSee('27 Nov 2026')
            ->assertSee('Payment received')
            ->assertSee('Balance due')
            ->assertSee('5,000.00')
            ->assertDontSee('Not verified')
            ->assertDontSee('Not recorded')
            ->assertDontSee('Customer payment is not recorded on this invoice.')
            ->assertDontSee('Unit prices include');
    }

    public function test_real_supply_date_can_differ_without_changing_invoice_date(): void
    {
        $owner = $this->owner();
        app(CompanyProfileService::class)->save($this->profileData(logoPath: null), $owner);
        $invoice = app(TaxInvoiceService::class)->create([
            'customer_name' => 'Customer', 'customer_address' => 'Dubai, UAE',
            'order_reference' => 'DELIVERED-LATER', 'invoice_date' => '2026-11-27',
            'supply_date' => '2026-11-28',
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['description' => 'Laptop', 'quantity' => 1, 'unit_price_including_vat' => '105.00']],
        ], $owner);

        $this->assertSame('2026-11-28', $invoice->seller_snapshot['supply_date_snapshot']);
        $this->assertSame('2026-11-27', $invoice->invoice_date->toDateString());

        $this->actingAs($owner)->get(route('tax-invoices.pdf', ['invoice' => $invoice, 'print' => 1]))
            ->assertOk()
            ->assertSee('27 Nov 2026')
            ->assertSee('28 Nov 2026');
    }

    public function test_staff_cannot_mark_an_invoice_paid_even_when_able_to_issue_it(): void
    {
        $owner = $this->owner();
        app(CompanyProfileService::class)->save($this->profileData(logoPath: null), $owner);
        $staff = User::factory()->create();
        Employee::factory()->for($staff)->role(EmployeeRole::Staff)->create([
            'email' => $staff->email, 'status' => true,
        ]);

        try {
            app(TaxInvoiceService::class)->create([
                'customer_name' => 'Customer', 'customer_address' => 'Dubai',
                'order_reference' => 'NOT-PAID', 'invoice_date' => '2026-11-27',
                'payment_received_in_full' => true, 'idempotency_key' => (string) Str::uuid(),
                'items' => [['description' => 'Laptop', 'quantity' => 1, 'unit_price_including_vat' => '105.00']],
            ], $staff);
            $this->fail('Staff member was allowed to confirm customer payment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment_received_in_full', $exception->errors());
        }
        $this->assertDatabaseCount('tax_invoices', 0);
    }

    public function test_print_document_matches_reference_structure_bilingual_content_logo_and_prices(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('company-profile/logo.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII=',
        ));
        $owner = $this->owner();
        app(CompanyProfileService::class)->save($this->profileData(), $owner);
        $invoice = $this->invoice($owner);

        $response = $this->actingAs($owner)->get(route('tax-invoices.pdf', [
            'invoice' => $invoice,
            'print' => 1,
        ]));

        $response->assertOk()
            ->assertSeeInOrder([
                'Tech Point Zone Electronics Trading L.L.C',
                'تك بوينت زون لتجارة الالكترونيات ذ.م.م',
                'TAX INVOICE',
                'فاتورة ضريبية',
                'Trade License No:',
                'BILL TO',
                'ORDER ID',
                'Description / Serial Number',
                '530.00',
                'Subtotal (excl. VAT)',
                '504.76',
                'Invoice total (AED)',
                'Terms &amp; Conditions',
                '1 Year Manufacturer Warranty.',
                'ضمان الشركة المصنعة لمدة سنة واحدة.',
                'Legal company statement.',
                'بيان الشركة القانوني.',
            ], false)
            ->assertSee('data:image/png;base64,', false)
            ->assertSee('@page { size: A4 portrait;', false)
            ->assertSee('window.print()', false)
            ->assertDontSee('GRAND TOTAL AED AED')
            ->assertDontSee('Customer TRN:');
    }

    public function test_customer_trn_is_rendered_only_when_present(): void
    {
        $owner = $this->owner();
        app(CompanyProfileService::class)->save($this->profileData(logoPath: null), $owner);
        $withoutTrn = $this->invoice($owner);
        $withTrn = $this->invoice($owner, customerTrn: '100200300400500', orderReference: 'ORDER-2');

        $this->actingAs($owner)
            ->get(route('tax-invoices.pdf', ['invoice' => $withoutTrn, 'print' => 1]))
            ->assertOk()
            ->assertDontSee('Customer TRN:');

        $this->actingAs($owner)
            ->get(route('tax-invoices.pdf', ['invoice' => $withTrn, 'print' => 1]))
            ->assertOk()
            ->assertSee('Customer TRN:')
            ->assertSee('class="value invoice-trn" dir="ltr">100200300400500</td>', false);
    }

    public function test_dompdf_receives_connected_visual_order_arabic_and_generates_one_item_pdf(): void
    {
        $owner = $this->owner();
        app(CompanyProfileService::class)->save($this->profileData(logoPath: null), $owner);
        $invoice = $this->invoice($owner);
        $arabic = app(ArabicPdfText::class);
        $shapedHeading = $arabic->forDompdf('فاتورة ضريبية');

        $this->assertSame(
            'efba94efbbb4efba92efbbb3efbaaeefbabf20efba93efbaadefbbaeefba97efba8eefbb93',
            bin2hex($shapedHeading),
        );
        $this->assertMatchesRegularExpression('/[\x{FE70}-\x{FEFF}]/u', $shapedHeading);

        $html = view('invoices.tax-invoice', [
            'invoice' => $invoice,
            'arabic' => $arabic,
            'logoDataUri' => null,
            'verificationUrl' => app(InvoiceVerificationService::class)->url($invoice),
            'documentMode' => 'pdf',
        ])->render();

        $this->assertStringContainsString($shapedHeading, $html);
        $this->assertStringContainsString($arabic->forDompdf('بيان الشركة القانوني.'), $html);

        $response = $this->actingAs($owner)->get(route('tax-invoices.pdf', ['invoice' => $invoice]));
        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_print_action_uses_official_document_route_and_void_remains_visible(): void
    {
        $owner = $this->owner();
        app(CompanyProfileService::class)->save($this->profileData(logoPath: null), $owner);
        $invoice = $this->invoice($owner);

        Livewire::actingAs($owner)
            ->test(ViewTaxInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionHasUrl('print', route('tax-invoices.pdf', ['invoice' => $invoice, 'print' => 1]))
            ->assertActionShouldOpenUrlInNewTab('print');

        app(TaxInvoiceService::class)->void($invoice, 'Focused document parity test.', $owner);

        $this->actingAs($owner)
            ->get(route('tax-invoices.pdf', ['invoice' => $invoice, 'print' => 1]))
            ->assertOk()
            ->assertSee('<div class="void">VOID</div>', false);
    }

    public function test_stamp_is_snapshotted_and_shared_by_pdf_and_print_without_changing_old_invoices(): void
    {
        Storage::fake('public');
        $firstStamp = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII=');
        $secondStamp = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAAAAAA6fptVAAAACklEQVR4nGNgAAAAAgABSK+kcQAAAABJRU5ErkJggg==');
        Storage::disk('public')->put('company-profile/stamp-first.png', $firstStamp);
        Storage::disk('public')->put('company-profile/stamp-second.png', $secondStamp);

        $owner = $this->owner();
        app(CompanyProfileService::class)->save([
            ...$this->profileData(logoPath: null),
            'stamp_path' => 'company-profile/stamp-first.png',
        ], $owner);
        $invoice = $this->invoice($owner);

        app(CompanyProfileService::class)->save([
            ...$this->profileData(logoPath: null),
            'stamp_path' => 'company-profile/stamp-second.png',
        ], $owner);

        $this->assertSame('company-profile/stamp-first.png', $invoice->fresh()->seller_snapshot['stamp_path']);

        $print = $this->actingAs($owner)->get(route('tax-invoices.pdf', ['invoice' => $invoice, 'print' => 1]));
        $print->assertOk()
            ->assertSeeInOrder(['class="stamp"', 'Invoice total (AED)', 'class="footer"'], false)
            ->assertSee('class="document-page bottom-footer"', false)
            ->assertDontSee('class="footer-spacer"', false)
            ->assertSee(base64_encode($firstStamp), false)
            ->assertDontSee(base64_encode($secondStamp), false)
            ->assertSee('Trade License No:')
            ->assertSee('بيان الشركة القانوني.');

        $pdf = $this->actingAs($owner)->get(route('tax-invoices.pdf', ['invoice' => $invoice]));
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertSame(1, preg_match_all('/\/Type\s*\/Page(?!s)/', $pdf->getContent()));
    }

    public function test_invoice_without_stamp_has_a_clean_bottom_footer(): void
    {
        $owner = $this->owner();
        app(CompanyProfileService::class)->save($this->profileData(logoPath: null), $owner);
        $invoice = $this->invoice($owner);

        $this->actingAs($owner)
            ->get(route('tax-invoices.pdf', ['invoice' => $invoice, 'print' => 1]))
            ->assertOk()
            ->assertSee('class="document-page bottom-footer"', false)
            ->assertSee('class="footer"', false)
            ->assertDontSee('class="footer-spacer"', false)
            ->assertDontSee('class="stamp-area"', false)
            ->assertDontSee('Company stamp');
    }

    public function test_three_normal_items_generate_one_page_without_separating_the_closing_section(): void
    {
        $owner = $this->owner();
        app(CompanyProfileService::class)->save($this->profileData(logoPath: null), $owner);
        $items = collect(range(1, 3))->map(fn (int $line): array => [
            'description' => "Laptop product {$line} - normal invoice line",
            'quantity' => 1,
            'unit_price_including_vat' => '530.00',
        ])->all();
        $invoice = $this->invoice($owner, orderReference: 'ORDER-THREE', items: $items);

        $this->actingAs($owner)
            ->get(route('tax-invoices.pdf', ['invoice' => $invoice, 'print' => 1]))
            ->assertOk()
            ->assertSee('class="document-page bottom-footer"', false)
            ->assertDontSee('class="footer-spacer"', false);

        $response = $this->actingAs($owner)->get(route('tax-invoices.pdf', ['invoice' => $invoice]));

        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertSame(1, preg_match_all('/\/Type\s*\/Page(?!s)/', $response->getContent()));
    }

    public function test_long_multi_item_invoice_flows_to_multiple_pages_with_one_ordered_closing_section(): void
    {
        $owner = $this->owner();
        app(CompanyProfileService::class)->save($this->profileData(logoPath: null), $owner);
        $items = collect(range(1, 10))->map(fn (int $line): array => [
            'description' => str_pad("Long product description {$line}: laptop with extended processor, memory, storage, display, warranty and configuration details.", 250, ' details'),
            'quantity' => 1,
            'unit_price_including_vat' => '530.00',
        ])->all();
        $invoice = $this->invoice($owner, orderReference: 'ORDER-LONG', items: $items);

        $print = $this->actingAs($owner)->get(route('tax-invoices.pdf', ['invoice' => $invoice, 'print' => 1]));
        $print->assertOk()
            ->assertSee('display: table-header-group', false)
            ->assertSee('page-break-inside: avoid', false)
            ->assertSeeInOrder(['class="items"', 'class="closing-section long-layout"', 'Invoice total (AED)', 'class="company-footer"', 'Verify this invoice:'], false)
            ->assertDontSee('class="footer-spacer"', false);

        $pdf = $this->actingAs($owner)->get(route('tax-invoices.pdf', ['invoice' => $invoice]));
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertGreaterThanOrEqual(2, preg_match_all('/\/Type\s*\/Page(?!s)/', $pdf->getContent()));
    }

    /** @param array<int, array<string, mixed>>|null $items */
    private function invoice(User $actor, ?string $customerTrn = null, string $orderReference = 'ORDER-1', ?array $items = null): TaxInvoice
    {
        return app(TaxInvoiceService::class)->create([
            'customer_name' => 'Majd',
            'customer_address' => 'Dubai, United Arab Emirates',
            'customer_trn' => $customerTrn,
            'order_reference' => $orderReference,
            'invoice_date' => '2026-08-24',
            'idempotency_key' => (string) Str::uuid(),
            'items' => $items ?? [[
                'description' => 'Apple MacBook Air 13.3-inch 2017',
                'quantity' => 1,
                'unit_price_including_vat' => '530.00',
            ]],
        ], $actor);
    }

    /** @return array<string, mixed> */
    private function profileData(?string $logoPath = 'company-profile/logo.png'): array
    {
        return [
            'company_name_en' => 'Tech Point Zone Electronics Trading L.L.C',
            'company_name_ar' => 'تك بوينت زون لتجارة الالكترونيات ذ.م.م',
            'logo_path' => $logoPath,
            'trn' => '100517085500003',
            'trade_license_number' => '897263',
            'ded_registration_number' => '1504493',
            'address_en' => 'Hana & Maryam Obaid Al Helo Building - Shop 8, Dubai, UAE',
            'address_ar' => 'بناية هنا ومريم عبيد الحلو - محل رقم 8، دبي، الإمارات العربية المتحدة',
            'phone' => '+971-52-5696022',
            'mobile' => '+971-55-4949129',
            'email' => 'sales@techpointzone.com',
            'legal_statement_en' => 'Legal company statement.',
            'legal_statement_ar' => 'بيان الشركة القانوني.',
        ];
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role(EmployeeRole::Owner)->create([
            'email' => $user->email,
            'status' => true,
        ]);

        return $user->refresh();
    }
}
