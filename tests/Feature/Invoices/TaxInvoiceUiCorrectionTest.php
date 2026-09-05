<?php

namespace Tests\Feature\Invoices;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\InvoicePermission;
use App\Filament\Pages\Administration\CompanyProfile;
use App\Filament\Resources\TaxInvoices\Pages\CreateTaxInvoice;
use App\Filament\Resources\TaxInvoices\Pages\ListTaxInvoices;
use App\Filament\Resources\TaxInvoices\Pages\ViewTaxInvoice;
use App\Filament\Resources\TaxInvoices\TaxInvoiceResource;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\TaxInvoice;
use App\Models\User;
use App\Services\CompanyProfileService;
use App\Services\Invoices\TaxInvoiceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class TaxInvoiceUiCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_sees_new_invoice_action_and_denied_user_does_not(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);

        Livewire::actingAs($owner)->test(ListTaxInvoices::class)->assertActionVisible('create');

        EmployeePermissionOverride::query()->create([
            'employee_id' => $staff->employee->id,
            'permission_key' => InvoicePermission::Create->value,
            'effect' => EmployeePermissionEffect::Deny,
            'granted_by_user_id' => $owner->id,
            'reason' => 'Focused Invoice UI authorization test.',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($staff->employee->id);

        Livewire::actingAs($staff)->test(ListTaxInvoices::class)->assertActionHidden('create');
        $this->actingAs($staff)->get('/admin/tax-invoices/create')->assertForbidden();
    }

    public function test_company_profile_renders_separate_large_english_and_arabic_fields_and_logo_section(): void
    {
        $owner = $this->user(EmployeeRole::Owner);

        $this->actingAs($owner)->get(CompanyProfile::getUrl())
            ->assertOk()
            ->assertSee('Company Identity')
            ->assertSee('Contact Information')
            ->assertSee('Registered Address')
            ->assertSee('Address — English')
            ->assertSee('Address — Arabic')
            ->assertSee('Legal Statement — English')
            ->assertSee('Legal Statement — Arabic')
            ->assertSee('Branding')
            ->assertSee('Company Logo')
            ->assertSee('Company Stamp')
            ->assertSee('No logo uploaded')
            ->assertSee('No stamp uploaded')
            ->assertSee('Save Company Profile')
            ->assertSee('Saving...')
            ->assertSee('max-w-7xl', false)
            ->assertSee('id="company-profile-company-name-ar"', false)
            ->assertSee('id="company-profile-ded-registration-number"', false)
            ->assertSee('id="company-profile-emirate"', false)
            ->assertSee('wire:model="data.company_name_ar"', false)
            ->assertSee('wire:model="data.ded_registration_number"', false)
            ->assertSee('wire:model="data.emirate"', false)
            ->assertSee('id="company-profile-address-ar"', false)
            ->assertSee('id="company-profile-legal-ar"', false)
            ->assertSee('w-full max-w-full min-w-0', false)
            ->assertSee('width: 100%; max-width: 100%; min-width: 0;', false)
            ->assertSee('min-height: 9rem; resize: vertical;', false)
            ->assertSee('min-height: 12rem; resize: vertical;', false)
            ->assertSee('dir="rtl"', false)
            ->assertSee('accept="image/jpeg,image/png,image/webp"', false)
            ->assertSee('class="sr-only"', false);
    }

    public function test_arabic_identity_address_and_legal_fields_are_editable_and_persist(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        app(CompanyProfileService::class)->save($this->profileData(), $owner);

        Livewire::actingAs($owner)->test(CompanyProfile::class)
            ->set('data.company_name_ar', 'شركة تك بوينت زون')
            ->set('data.ded_registration_number', 'DED-UPDATED')
            ->set('data.emirate', 'Dubai')
            ->set('data.address_en', "Line one\nLine two")
            ->set('data.address_ar', "السطر الأول\nالسطر الثاني")
            ->set('data.legal_statement_en', "Legal line one\nLegal line two")
            ->set('data.legal_statement_ar', "النص القانوني الأول\nالنص القانوني الثاني")
            ->call('save')
            ->assertHasNoErrors()
            ->assertNotified('Company Profile saved');

        $this->assertDatabaseHas('company_profiles', [
            'company_name_ar' => 'شركة تك بوينت زون',
            'ded_registration_number' => 'DED-UPDATED',
            'emirate' => 'Dubai',
            'address_en' => "Line one\nLine two",
            'address_ar' => "السطر الأول\nالسطر الثاني",
            'legal_statement_en' => "Legal line one\nLegal line two",
            'legal_statement_ar' => "النص القانوني الأول\nالنص القانوني الثاني",
        ]);
    }

    public function test_owner_can_upload_safe_company_logo_and_stamp_and_staff_cannot_manage_them(): void
    {
        Storage::fake('public');
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        app(CompanyProfileService::class)->save($this->profileData(), $owner);

        Livewire::actingAs($owner)->test(CompanyProfile::class)
            ->set('logo', UploadedFile::fake()->createWithContent('company-logo.png', base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII=',
            )))
            ->set('stamp', UploadedFile::fake()->createWithContent('company-stamp.png', base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII=',
            )))
            ->call('save')
            ->assertHasNoErrors()
            ->assertNotified('Company Profile saved');

        $logoPath = (string) \App\Models\CompanyProfile::query()->sole()->logo_path;
        $stampPath = (string) \App\Models\CompanyProfile::query()->sole()->stamp_path;
        $this->assertStringStartsWith('company-profile/', $logoPath);
        $this->assertStringStartsWith('company-profile/', $stampPath);
        $this->assertStringNotContainsString('company-logo', $logoPath);
        $this->assertStringNotContainsString('company-stamp', $stampPath);
        Storage::disk('public')->assertExists($logoPath);
        Storage::disk('public')->assertExists($stampPath);

        $this->expectException(AuthorizationException::class);
        app(CompanyProfileService::class)->save(['stamp_path' => 'company-profile/unauthorized.png'], $staff);
    }

    public function test_invoice_create_form_keeps_one_step_fields_items_totals_and_professional_actions(): void
    {
        $owner = $this->user(EmployeeRole::Owner);

        Livewire::actingAs($owner)->test(CreateTaxInvoice::class)
            ->assertFormFieldExists('customer_name')
            ->assertFormFieldExists('customer_address')
            ->assertFormFieldExists('customer_trn')
            ->assertFormFieldExists('order_reference')
            ->assertFormFieldExists('invoice_date')
            ->assertFormFieldExists('items')
            ->assertSee('Product Name / Description')
            ->assertSee('Qty')
            ->assertSee('Unit Price Incl. VAT')
            ->assertSee('Line Total Incl. VAT')
            ->assertSee('+ Add Item')
            ->assertSee('Totals Summary')
            ->assertSee('Enter VAT-inclusive prices. VAT and totals are calculated automatically.')
            ->assertSee('Invoice number will be assigned when saved.')
            ->assertSee('Save Invoice')
            ->assertSee('Saving...')
            ->assertSee('Save &amp; Print', false)
            ->assertSee('Creating Invoice...')
            ->assertSee('Cancel');
    }

    public function test_save_invoice_creates_once_and_redirects_to_view(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        app(CompanyProfileService::class)->save($this->profileData(), $owner);

        $component = Livewire::actingAs($owner)->test(CreateTaxInvoice::class)
            ->fillForm($this->invoiceFormData())
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Invoice created');

        $invoice = TaxInvoice::query()->sole();

        $component->assertRedirect(TaxInvoiceResource::getUrl('view', ['record' => $invoice]));
        $this->assertDatabaseCount('tax_invoice_items', 1);
        $this->assertSame('TP-INV 9153', $invoice->invoice_number);
        $this->assertNull($invoice->customer_trn);
    }

    public function test_save_and_print_creates_once_and_reaches_pdf_output(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        app(CompanyProfileService::class)->save($this->profileData(), $owner);

        $component = Livewire::actingAs($owner)->test(CreateTaxInvoice::class)
            ->fillForm($this->invoiceFormData())
            ->call('saveAndPrint')
            ->assertHasNoFormErrors()
            ->assertNotified('Invoice created');

        $invoice = TaxInvoice::query()->sole();

        $component->assertRedirect(TaxInvoiceResource::getUrl('index'));
        $component->assertSet('data', []);
        $this->assertStringContainsString('window.open(', data_get($component->effects, 'xjs.0.expression', ''));
        $this->actingAs($owner)->get(route('tax-invoices.pdf', ['invoice' => $invoice]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertDatabaseCount('tax_invoices', 1);
        $this->assertDatabaseCount('tax_invoice_items', 1);
    }

    public function test_validation_errors_are_visible_and_do_not_create_an_invoice(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        app(CompanyProfileService::class)->save($this->profileData(), $owner);
        $data = $this->invoiceFormData();
        $data['customer_name'] = '';
        $data['customer_address'] = '';
        $data['order_reference'] = '';

        Livewire::actingAs($owner)->test(CreateTaxInvoice::class)
            ->fillForm($data)
            ->call('create')
            ->assertHasFormErrors([
                'customer_name' => 'required',
                'customer_address' => 'required',
                'order_reference' => 'required',
            ]);

        $this->assertDatabaseCount('tax_invoices', 0);
        $this->assertDatabaseCount('tax_invoice_items', 0);
    }

    public function test_double_submission_creates_exactly_one_invoice(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        app(CompanyProfileService::class)->save($this->profileData(), $owner);

        $component = Livewire::actingAs($owner)->test(CreateTaxInvoice::class)
            ->fillForm($this->invoiceFormData())
            ->call('create');

        $component->call('create');

        $this->assertDatabaseCount('tax_invoices', 1);
        $this->assertDatabaseCount('tax_invoice_items', 1);
    }

    public function test_save_then_print_cannot_issue_a_second_invoice(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        app(CompanyProfileService::class)->save($this->profileData(), $owner);

        $component = Livewire::actingAs($owner)->test(CreateTaxInvoice::class)
            ->fillForm($this->invoiceFormData())
            ->call('create');

        $component->call('saveAndPrint');

        $this->assertDatabaseCount('tax_invoices', 1);
        $this->assertDatabaseCount('tax_invoice_items', 1);
    }

    public function test_known_service_precondition_failure_shows_safe_feedback(): void
    {
        $owner = $this->user(EmployeeRole::Owner);

        Livewire::actingAs($owner)->test(CreateTaxInvoice::class)
            ->fillForm($this->invoiceFormData())
            ->call('create')
            ->assertNotified('Invoice could not be created');

        $this->assertDatabaseCount('tax_invoices', 0);
        $this->assertDatabaseCount('tax_invoice_items', 0);
    }

    public function test_pdf_failure_returns_to_the_created_invoice_with_safe_feedback(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        app(CompanyProfileService::class)->save($this->profileData(), $owner);
        $invoice = app(TaxInvoiceService::class)->create([
            ...$this->invoiceFormData(),
            'idempotency_key' => (string) Str::uuid(),
        ], $owner);

        Pdf::shouldReceive('loadView')->once()->andThrow(new \RuntimeException('Renderer diagnostics'));

        $this->actingAs($owner)
            ->get(route('tax-invoices.pdf', ['invoice' => $invoice]))
            ->assertRedirect("/admin/tax-invoices/{$invoice->id}")
            ->assertSessionHas('filament.notifications');

        $this->assertDatabaseCount('tax_invoices', 1);
        $this->assertDatabaseCount('tax_invoice_items', 1);
    }

    public function test_invoice_view_has_back_pdf_print_and_authorized_void_actions(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        app(CompanyProfileService::class)->save($this->profileData(), $owner);
        $invoice = app(TaxInvoiceService::class)->create([
            ...$this->invoiceFormData(),
            'idempotency_key' => (string) Str::uuid(),
        ], $owner);

        Livewire::actingAs($owner)
            ->test(ViewTaxInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionVisible('back')
            ->assertActionVisible('pdf')
            ->assertActionVisible('print')
            ->assertActionVisible('void');
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'status' => true]);

        return $user->refresh();
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

    private function invoiceFormData(): array
    {
        return [
            'customer_name' => 'Walk-in Customer',
            'customer_address' => 'Dubai, UAE',
            'customer_trn' => null,
            'order_reference' => 'ORDER-UI-1',
            'invoice_date' => '2026-08-24',
            'items' => [[
                'description' => 'Laptop',
                'quantity' => 1,
                'unit_price_including_vat' => '530.00',
            ]],
        ];
    }
}
