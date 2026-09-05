<?php

namespace Tests\Feature\Invoices;

use App\Enums\EmployeeRole;
use App\Models\Employee;
use App\Models\TaxInvoice;
use App\Models\User;
use App\Services\CompanyProfileService;
use App\Services\Invoices\InvoiceVerificationService;
use App\Services\Invoices\TaxInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class TaxInvoiceVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_issued_invoices_receive_unique_unpredictable_tokens(): void
    {
        $owner = $this->owner();
        app(CompanyProfileService::class)->save($this->profileData(), $owner);

        $first = $this->invoice($owner, 'ORDER-VERIFY-1');
        $second = $this->invoice($owner, 'ORDER-VERIFY-2');

        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $first->verification_token);
        $this->assertNotSame($first->verification_token, $second->verification_token);
        $this->assertSame(
            route('invoice.verify', ['token' => $first->verification_token]),
            app(InvoiceVerificationService::class)->url($first),
        );
    }

    public function test_public_verification_shows_only_the_safe_invoice_summary(): void
    {
        $owner = $this->owner();
        app(CompanyProfileService::class)->save($this->profileData(), $owner);
        $invoice = $this->invoice($owner, 'ORDER-VERIFY-SAFE');

        $this->get(route('invoice.verify', ['token' => $invoice->verification_token]))
            ->assertOk()
            ->assertSee('VALID INVOICE')
            ->assertSee($invoice->invoice_number)
            ->assertSee('ORDER-VERIFY-SAFE')
            ->assertSee('Walk-in Customer')
            ->assertSee('AED 530.00')
            ->assertSee('Tech Point Zone')
            ->assertSee('100000000000001')
            ->assertDontSee('Customer private address')
            ->assertDontSee('999888777666555')
            ->assertDontSee('created_by_user_id')
            ->assertDontSee('idempotency_key');
    }

    public function test_invalid_token_is_safe_and_void_invoice_keeps_its_token(): void
    {
        $owner = $this->owner();
        app(CompanyProfileService::class)->save($this->profileData(), $owner);

        $this->get('/invoice/verify/not-a-token')
            ->assertOk()
            ->assertSee('Invoice could not be verified.');

        $invoice = $this->invoice($owner, 'ORDER-VOID');
        $token = $invoice->verification_token;
        app(TaxInvoiceService::class)->void($invoice, 'Verification status test.', $owner);

        $this->assertSame($token, $invoice->fresh()->verification_token);
        $this->get(route('invoice.verify', ['token' => $token]))
            ->assertOk()
            ->assertSee('VOID INVOICE')
            ->assertSee('VOID');
    }

    public function test_pdf_and_print_share_the_verification_qr_and_token_is_stable(): void
    {
        $owner = $this->owner();
        app(CompanyProfileService::class)->save($this->profileData(), $owner);
        $invoice = $this->invoice($owner, 'ORDER-QR');
        $token = $invoice->verification_token;
        $verificationUrl = app(InvoiceVerificationService::class)->url($invoice);

        $this->actingAs($owner)
            ->get(route('tax-invoices.pdf', ['invoice' => $invoice, 'print' => 1]))
            ->assertOk()
            ->assertSee('Verify Invoice Authenticity')
            ->assertSeeInOrder([
                'Invoice verification QR code',
                'Verify this invoice:',
                'class="company-footer"',
            ], false)
            ->assertSee($verificationUrl)
            ->assertSee('href="'.$verificationUrl.'"', false)
            ->assertSee('data:image/png;base64,', false)
            ->assertSee('Invoice verification QR code');

        $pdf = $this->actingAs($owner)
            ->get(route('tax-invoices.pdf', ['invoice' => $invoice]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringContainsString($verificationUrl, (string) $pdf->getContent());
        $this->assertSame($token, $invoice->fresh()->verification_token);
    }

    public function test_verification_url_uses_app_url_named_route_and_never_sequential_id(): void
    {
        config(['app.url' => 'https://erp.techpointzone.example']);
        $owner = $this->owner();
        app(CompanyProfileService::class)->save($this->profileData(), $owner);
        $invoice = $this->invoice($owner, 'ORDER-PRODUCTION-URL');

        $url = app(InvoiceVerificationService::class)->url($invoice);

        $this->assertSame(
            'https://erp.techpointzone.example/invoice/verify/'.$invoice->verification_token,
            $url,
        );
        $this->assertStringNotContainsString('/invoice/verify/'.$invoice->id, $url);
        $this->assertStringNotContainsString('localhost', $url);
        $this->assertStringNotContainsString('127.0.0.1', $url);
    }

    public function test_missing_historical_token_is_reported_without_silent_regeneration(): void
    {
        $invoice = new TaxInvoice(['verification_token' => null]);

        $this->assertNull($invoice->verification_token);
        $this->expectException(LogicException::class);
        app(InvoiceVerificationService::class)->url($invoice);
    }

    private function invoice(User $owner, string $order): TaxInvoice
    {
        return app(TaxInvoiceService::class)->create([
            'customer_name' => 'Walk-in Customer',
            'customer_address' => 'Customer private address',
            'customer_trn' => '999888777666555',
            'order_reference' => $order,
            'invoice_date' => '2026-08-24',
            'idempotency_key' => (string) Str::uuid(),
            'items' => [[
                'description' => 'Laptop',
                'quantity' => 1,
                'unit_price_including_vat' => '530.00',
            ]],
        ], $owner);
    }

    private function profileData(): array
    {
        return [
            'company_name_en' => 'Tech Point Zone',
            'company_name_ar' => 'تك بوينت زون',
            'trn' => '100000000000001',
            'trade_license_number' => 'TL-1',
            'ded_registration_number' => 'DED-1',
            'address_en' => 'Dubai, UAE',
            'address_ar' => 'دبي، الإمارات',
            'phone' => '+9714000000',
            'mobile' => '+9715000000',
            'email' => 'accounts@example.test',
            'legal_statement_en' => 'Registered company statement.',
            'legal_statement_ar' => 'بيان الشركة المسجلة.',
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
