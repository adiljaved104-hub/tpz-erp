<?php

namespace Tests\Feature\Authentication;

use App\Enums\EmployeeRole;
use App\Http\Middleware\EnforceWebSecurityPolicy;
use App\Models\CompanyProfile;
use App\Models\Employee;
use App\Models\Product;
use App\Models\TaxInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Invoices\TaxInvoiceService;
use App\Services\Quotations\QuotationService;
use App\Services\Security\WebInactivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProtectedDocumentSecurityPolicyTest extends TestCase
{
    use RefreshDatabase;

    public static function documentRoutes(): array
    {
        return ['report export' => ['report'], 'Tax Invoice PDF' => ['invoice'], 'Quotation PDF' => ['quotation']];
    }

    #[DataProvider('documentRoutes')]
    public function test_fresh_authorized_session_can_download_real_document(string $kind): void
    {
        $owner = $this->user();
        $url = $this->documentUrl($kind, $owner);
        $response = $this->actingAs($owner)
            ->withSession([WebInactivityService::SESSION_KEY => now()->subMinutes(119)->timestamp])
            ->get($url);
        $response->assertOk();
        if ($kind === 'report') {
            $response->assertDownload('orders-'.now()->format('Y-m-d').'.csv');
        } else {
            $response->assertHeader('content-type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $response->getContent());
        }
        $this->assertAuthenticatedAs($owner);
    }

    #[DataProvider('documentRoutes')]
    public function test_expired_idle_session_cannot_download_and_is_invalidated(string $kind): void
    {
        $owner = $this->user();
        $url = $this->documentUrl($kind, $owner);
        $this->actingAs($owner)->withSession([
            WebInactivityService::SESSION_KEY => now()->subMinutes(121)->timestamp,
            'document_test_private' => 'clear-on-logout',
        ])->withHeader('Accept', 'text/html')->get($url)
            ->assertRedirect(route('filament.admin.auth.login'))
            ->assertSessionMissing('document_test_private');
        $this->assertGuest();
        $this->assertDatabaseMissing('activity_logs', ['event' => 'report.exported']);
    }

    #[DataProvider('documentRoutes')]
    public function test_expired_idle_ajax_download_returns_existing_security_response(string $kind): void
    {
        $owner = $this->user();
        $url = $this->documentUrl($kind, $owner);
        $this->actingAs($owner)->withSession([WebInactivityService::SESSION_KEY => now()->subMinutes(120)->timestamp])
            ->getJson($url)->assertUnauthorized()->assertJsonPath('code', 'session_inactive');
        $this->assertGuest();
    }

    #[DataProvider('documentRoutes')]
    public function test_expired_password_blocks_document_even_with_fresh_activity(string $kind): void
    {
        $owner = $this->user();
        $url = $this->documentUrl($kind, $owner);
        $owner->forceFill(['password_changed_at' => now()->subDays(366)])->save();
        $this->actingAs($owner)->withSession([
            WebInactivityService::SESSION_KEY => now()->timestamp,
            'document_test_private' => 'clear-on-logout',
        ])->withHeader('Accept', 'text/html')->get($url)
            ->assertRedirect(route('auth.password.request'))->assertSessionMissing('document_test_private');
        $this->assertGuest();
        $this->assertDatabaseHas('activity_logs', ['event' => 'auth_security.password_rotation_required', 'actor_user_id' => $owner->id]);
    }

    #[DataProvider('documentRoutes')]
    public function test_password_rotation_ajax_response_is_preserved(string $kind): void
    {
        $owner = $this->user();
        $url = $this->documentUrl($kind, $owner);
        $owner->forceFill(['password_changed_at' => now()->subDays(366)])->save();
        $this->actingAs($owner)->withSession([WebInactivityService::SESSION_KEY => now()->timestamp])
            ->getJson($url)->assertUnauthorized()->assertJsonPath('code', 'password_rotation_required');
        $this->assertGuest();
    }

    #[DataProvider('documentRoutes')]
    public function test_fresh_session_does_not_bypass_document_or_report_authorization(string $kind): void
    {
        $owner = $this->user();
        $url = $this->documentUrl($kind, $owner);
        $staff = $this->user(EmployeeRole::Staff);
        $this->actingAs($staff)->withSession([WebInactivityService::SESSION_KEY => now()->timestamp])
            ->get($url)->assertForbidden();
        $this->assertAuthenticatedAs($staff);
        $this->assertDatabaseMissing('activity_logs', ['event' => 'report.exported']);
    }

    #[DataProvider('documentRoutes')]
    public function test_guest_remains_unable_to_download_protected_document(string $kind): void
    {
        $owner = $this->user();
        $this->getJson($this->documentUrl($kind, $owner))->assertUnauthorized();
    }

    public function test_print_variants_cannot_bypass_expired_session(): void
    {
        $owner = $this->user();
        $invoiceUrl = $this->documentUrl('invoice', $owner).'?print=1';
        $quoteUrl = $this->documentUrl('quotation', $owner).'?print=1';
        foreach ([$invoiceUrl, $quoteUrl] as $url) {
            $this->actingAs($owner)->withSession([WebInactivityService::SESSION_KEY => now()->subMinutes(121)->timestamp])
                ->get($url)->assertRedirect(route('filament.admin.auth.login'));
            $this->assertGuest();
        }
    }

    public function test_background_document_requests_do_not_refresh_user_activity(): void
    {
        $owner = $this->user();
        $url = $this->documentUrl('invoice', $owner).'?print=1';
        $oldActivity = now()->subMinutes(90)->timestamp;
        $this->actingAs($owner)->withSession([WebInactivityService::SESSION_KEY => $oldActivity])
            ->getJson($url)->assertOk()->assertSessionHas(WebInactivityService::SESSION_KEY, $oldActivity);
        $this->withHeader('Accept', 'text/html')->withHeader('X-Livewire', 'true')
            ->get($url)->assertOk()->assertSessionHas(WebInactivityService::SESSION_KEY, $oldActivity);
        $this->travel(31)->minutes();
        $this->getJson($url)->assertUnauthorized()->assertJsonPath('code', 'session_inactive');
    }

    public function test_public_token_verification_is_unchanged_for_guests_and_expired_sessions(): void
    {
        $owner = $this->user();
        $invoice = $this->invoice($owner);
        $url = route('invoice.verify', ['token' => $invoice->verification_token]);
        $this->get($url)->assertOk()->assertSee('VALID INVOICE')->assertSee($invoice->invoice_number);
        $this->get(route('invoice.verify', ['token' => 'invalid-token']))->assertOk()->assertSee('Invoice could not be verified.');
        $owner->forceFill(['password_changed_at' => now()->subDays(366)])->save();
        $this->actingAs($owner)->withSession([WebInactivityService::SESSION_KEY => now()->subMinutes(121)->timestamp])
            ->get($url)->assertOk()->assertSee('VALID INVOICE');

        $publicMiddleware = Route::getRoutes()->getByName('invoice.verify')->gatherMiddleware();
        $this->assertNotContains('auth', $publicMiddleware);
        $this->assertNotContains(EnforceWebSecurityPolicy::class, $publicMiddleware);
    }

    public function test_all_protected_document_routes_use_the_shared_policy(): void
    {
        foreach (['reports.export', 'tax-invoices.pdf', 'quotations.pdf'] as $name) {
            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();
            $this->assertContains('auth', $middleware);
            $this->assertContains(EnforceWebSecurityPolicy::class, $middleware);
        }
    }

    private function documentUrl(string $kind, User $owner): string
    {
        if ($kind === 'report') {
            return route('reports.export', ['report' => 'orders', 'format' => 'csv']);
        }
        if ($kind === 'invoice') {
            return route('tax-invoices.pdf', ['invoice' => $this->invoice($owner)]);
        }
        $this->profile($owner);
        $product = Product::factory()->create();
        $quotation = app(QuotationService::class)->create([
            'warehouse_id' => Warehouse::factory()->create()->id,
            'document_type' => 'quotation',
            'quotation_date' => today()->toDateString(),
            'valid_until' => today()->addDays(14)->toDateString(),
            'customer_name' => 'Route Test Customer',
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'description' => $product->name, 'quantity' => 1, 'unit_price_including_vat' => '105.00', 'discount_amount' => '0.00', 'vat_rate' => '5.0000']],
        ], $owner);

        return route('quotations.pdf', ['quotation' => $quotation]);
    }

    private function invoice(User $owner): TaxInvoice
    {
        $this->profile($owner);

        return app(TaxInvoiceService::class)->create([
            'customer_name' => 'Route Test Customer',
            'customer_address' => 'Private customer address',
            'order_reference' => 'ROUTE-TEST',
            'invoice_date' => today()->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['description' => 'Laptop', 'quantity' => 1, 'unit_price_including_vat' => '530.00']],
        ], $owner);
    }

    private function profile(User $owner): void
    {
        CompanyProfile::query()->firstOrCreate(['id' => 1], [
            'company_name_en' => 'Tech Point Zone', 'company_name_ar' => 'تك بوينت زون',
            'trn' => '100000000000001', 'address_en' => 'Dubai UAE',
            'legal_statement_en' => 'Registered company.', 'updated_by_user_id' => $owner->id,
        ]);
    }

    private function user(EmployeeRole $role = EmployeeRole::Owner): User
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com', 'password_changed_at' => now()]);
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'status' => true]);

        return $user->refresh()->load('employee');
    }
}
