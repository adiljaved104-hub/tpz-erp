<?php

namespace Tests\Feature\Invoices;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\CompanyProfilePermission;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Filament\Pages\Administration\CompanyProfile as CompanyProfilePage;
use App\Models\CompanyProfile;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\TaxInvoice;
use App\Models\User;
use App\Services\Authorization\CompanyProfileAuthorization;
use App\Services\CompanyProfileService;
use App\Services\Invoices\InvoiceSettingsService;
use App\Services\Invoices\TaxInvoiceService;
use App\Services\Search\GlobalSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class TaxInvoiceFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_profile_is_singleton_audited_and_uses_access_control(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $service = app(CompanyProfileService::class);
        $service->save($this->profileData('Tech Point Zone'), $owner);
        $service->save($this->profileData('Tech Point Zone LLC'), $owner);
        $this->assertDatabaseCount('company_profiles', 1);
        $this->assertSame('Tech Point Zone LLC', CompanyProfile::query()->sole()->company_name_en);
        $this->assertFalse(app(CompanyProfileAuthorization::class)->allows($staff, CompanyProfilePermission::View));
        EmployeePermissionOverride::query()->create(['employee_id' => $staff->employee->id, 'permission_key' => CompanyProfilePermission::View->value, 'effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $owner->id, 'reason' => 'Focused profile test']);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($staff->employee->id);
        $this->assertTrue(app(CompanyProfileAuthorization::class)->allows($staff, CompanyProfilePermission::View));
        $this->assertDatabaseHas('activity_logs', ['event' => 'company_profile.updated', 'subject_type' => 'company_profile']);
    }

    public function test_company_profile_page_rejects_unsafe_logo_and_staff_direct_access(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);

        $this->actingAs($staff)->get(CompanyProfilePage::getUrl())->assertForbidden();
        Livewire::actingAs($owner)->test(CompanyProfilePage::class)
            ->set('data.company_name_en', 'Tech Point Zone')
            ->set('logo', UploadedFile::fake()->create('document.pdf', 20, 'application/pdf'))
            ->call('save')->assertHasErrors(['logo']);
        $this->assertDatabaseCount('company_profiles', 0);
    }

    public function test_single_and_multiple_items_use_vat_inclusive_decimal_calculation_and_unique_sequence(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        app(CompanyProfileService::class)->save($this->profileData('Tech Point Zone'), $owner);
        $first = $this->invoice($owner, [['description' => 'Laptop', 'quantity' => 1, 'unit_price_including_vat' => '530.00']]);
        $second = $this->invoice($owner, [['description' => 'Laptop', 'quantity' => 2, 'unit_price_including_vat' => '100.00'], ['description' => 'Mouse', 'quantity' => 1, 'unit_price_including_vat' => '50.00']]);
        $this->assertSame('TP-INV 9153', $first->invoice_number);
        $this->assertSame('504.76', $first->subtotal_excluding_vat);
        $this->assertSame('25.24', $first->vat_amount);
        $this->assertSame('530.00', $first->grand_total);
        $this->assertNotSame($first->invoice_number, $second->invoice_number);
        $this->assertCount(2, $second->items);
    }

    public function test_invoice_snapshots_survive_profile_and_terms_changes_and_void_preserves_number(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $profiles = app(CompanyProfileService::class);
        $profiles->save($this->profileData('Original Company'), $owner);
        $invoice = $this->invoice($owner, [['description' => 'Laptop', 'quantity' => 1, 'unit_price_including_vat' => '105.00']]);
        $profiles->save($this->profileData('Changed Company'), $owner);
        app(InvoiceSettingsService::class)->save(['invoice_prefix' => 'NEW', 'starting_number' => 9999, 'vat_rate' => '7.00', 'terms_en' => 'Changed terms', 'terms_ar' => null], $owner);
        app(TaxInvoiceService::class)->void($invoice, 'Customer order cancelled', $owner);
        $invoice->refresh();
        $this->assertSame('Original Company', $invoice->seller_snapshot['company_name_en']);
        $this->assertStringContainsString('Manufacturer Warranty', $invoice->terms_en_snapshot);
        $this->assertSame('5.00', $invoice->vat_rate);
        $this->assertSame('TP-INV 9153', $invoice->invoice_number);
        $this->assertSame('void', $invoice->status);
    }

    public function test_authorized_invoice_pdf_is_generated_from_snapshot(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        app(CompanyProfileService::class)->save($this->profileData('Tech Point Zone'), $owner);
        $invoice = $this->invoice($owner, [['description' => 'Laptop', 'quantity' => 1, 'unit_price_including_vat' => '530.00']]);

        $this->actingAs($owner)->get(route('tax-invoices.pdf', ['invoice' => $invoice]))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_staff_sees_own_invoice_only_and_global_search_respects_scope(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $other = $this->user(EmployeeRole::Staff);
        app(CompanyProfileService::class)->save($this->profileData('Tech Point Zone'), $owner);
        $mine = $this->invoice($staff, [['description' => 'Laptop', 'quantity' => 1, 'unit_price_including_vat' => '100.00']], 'ORDER-MINE');
        $hidden = $this->invoice($other, [['description' => 'Other', 'quantity' => 1, 'unit_price_including_vat' => '100.00']], 'ORDER-HIDDEN');
        $this->actingAs($staff)->get('/admin/tax-invoices/'.$mine->id)->assertOk();
        $this->actingAs($staff)->get('/admin/tax-invoices/'.$hidden->id)->assertNotFound();
        $results = app(GlobalSearchService::class)->search($staff, 'ORDER');
        $labels = $results->flatten()->pluck('label')->all();
        $this->assertContains($mine->invoice_number, $labels);
        $this->assertNotContains($hidden->invoice_number, $labels);
    }

    private function invoice(User $actor, array $items, string $order = 'ORDER-1'): TaxInvoice
    {
        return app(TaxInvoiceService::class)->create(['customer_name' => 'Customer', 'customer_address' => 'Dubai', 'customer_trn' => null, 'order_reference' => $order, 'invoice_date' => '2026-08-24', 'idempotency_key' => (string) Str::uuid(), 'items' => $items], $actor);
    }

    private function profileData(string $name): array
    {
        return ['company_name_en' => $name, 'company_name_ar' => 'تك بوينت زون', 'trn' => '100000000000001', 'trade_license_number' => 'TL-1', 'ded_registration_number' => 'DED-1', 'address_en' => 'Dubai, UAE', 'address_ar' => 'دبي، الإمارات', 'phone' => '+9714000000', 'mobile' => '+9715000000', 'email' => 'accounts@example.test', 'legal_statement_en' => 'Registered company statement.', 'legal_statement_ar' => 'بيان الشركة المسجلة.'];
    }

    private function user(EmployeeRole $role): User
    {
        $u = User::factory()->create([
            'email' => fake()->unique()->userName().'@techpointzone.com',
        ]);
        Employee::factory()->for($u)->role($role)->create(['email' => $u->email, 'status' => true]);

        return $u->refresh();
    }
}
