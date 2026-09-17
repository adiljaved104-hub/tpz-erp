<?php

namespace Tests\Feature\Invoices;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\InvoicePermission;
use App\Filament\Resources\TaxInvoices\Pages\ListTaxInvoices;
use App\Filament\Resources\TaxInvoices\Pages\ViewTaxInvoice;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\TaxInvoice;
use App\Models\User;
use App\Services\CompanyProfileService;
use App\Services\Invoices\TaxInvoiceDocumentService;
use App\Services\Invoices\TaxInvoiceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class TaxInvoiceCustomerDetailsAmendmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_customer_field_can_be_corrected_independently(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->profile($owner);

        foreach ([
            'customer_name' => 'Corrected Name Only',
            'customer_trn' => '100200300400500',
            'customer_address' => 'Corrected Address Only',
        ] as $field => $value) {
            $invoice = $this->invoice($owner, 'ORDER-'.strtoupper($field));
            $data = [
                'customer_name' => $invoice->customer_name,
                'customer_trn' => $invoice->customer_trn,
                'customer_address' => $invoice->customer_address,
                'amendment_reason' => "Correct {$field} only.",
                $field => $value,
            ];

            $updated = app(TaxInvoiceService::class)->updateCustomerDetails($invoice, $data, $owner);

            $this->assertSame($value, $updated->{$field});
        }
    }

    public function test_authorized_employee_updates_only_customer_details_with_complete_audit_and_current_pdf_output(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $this->profile($owner);
        $this->allow($staff, InvoicePermission::EditCustomerDetails, $owner);
        $invoice = $this->invoice($staff);
        $invoice->load('items');
        $immutable = [
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date->toDateString(),
            'order_reference' => $invoice->order_reference,
            'subtotal_excluding_vat' => $invoice->subtotal_excluding_vat,
            'vat_amount' => $invoice->vat_amount,
            'grand_total' => $invoice->grand_total,
            'seller_snapshot' => $invoice->seller_snapshot,
            'status' => $invoice->status,
            'issued_at' => $invoice->issued_at->toJSON(),
            'created_by_user_id' => $invoice->created_by_user_id,
            'items' => $invoice->items->map->getRawOriginal()->all(),
        ];

        $updated = app(TaxInvoiceService::class)->updateCustomerDetails($invoice, [
            'customer_name' => 'Corrected Customer LLC',
            'customer_trn' => '100200300400500',
            'customer_address' => "Office 12\nDubai, UAE",
            'amendment_reason' => 'Customer supplied corrected legal details.',
        ], $staff);

        $this->assertSame('Corrected Customer LLC', $updated->customer_name);
        $this->assertSame('100200300400500', $updated->customer_trn);
        $this->assertSame("Office 12\nDubai, UAE", $updated->customer_address);
        $this->assertSame($immutable['invoice_number'], $updated->invoice_number);
        $this->assertSame($immutable['invoice_date'], $updated->invoice_date->toDateString());
        $this->assertSame($immutable['order_reference'], $updated->order_reference);
        $this->assertSame($immutable['subtotal_excluding_vat'], $updated->subtotal_excluding_vat);
        $this->assertSame($immutable['vat_amount'], $updated->vat_amount);
        $this->assertSame($immutable['grand_total'], $updated->grand_total);
        $this->assertSame($immutable['seller_snapshot'], $updated->seller_snapshot);
        $this->assertSame($immutable['status'], $updated->status);
        $this->assertSame($immutable['issued_at'], $updated->issued_at->toJSON());
        $this->assertSame($immutable['created_by_user_id'], $updated->created_by_user_id);
        $this->assertSame($immutable['items'], $updated->items()->get()->map->getRawOriginal()->all());

        $log = ActivityLog::query()
            ->where('event', 'tax_invoice.customer_details_updated')
            ->where('subject_id', $invoice->id)
            ->sole();
        $this->assertSame('Original Customer', $log->properties['previous_customer_name']);
        $this->assertSame('Corrected Customer LLC', $log->properties['new_customer_name']);
        $this->assertNull($log->properties['previous_customer_trn']);
        $this->assertSame('100200300400500', $log->properties['new_customer_trn']);
        $this->assertSame('Original Address', $log->properties['previous_customer_address']);
        $this->assertSame("Office 12\nDubai, UAE", $log->properties['new_customer_address']);
        $this->assertSame('Customer supplied corrected legal details.', $log->properties['reason']);
        $this->assertSame($staff->id, $log->properties['actor_id']);

        $this->actingAs($staff)
            ->get(route('tax-invoices.pdf', ['invoice' => $invoice, 'print' => 1]))
            ->assertOk()
            ->assertSee('Corrected Customer LLC')
            ->assertSee('100200300400500')
            ->assertSee('Office 12')
            ->assertSee($immutable['invoice_number']);
    }

    public function test_reason_is_required_and_void_invoice_cannot_be_amended(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->profile($owner);
        $invoice = $this->invoice($owner);

        try {
            app(TaxInvoiceService::class)->updateCustomerDetails($invoice, [
                'customer_name' => 'Changed Without Reason',
                'customer_trn' => null,
                'customer_address' => 'Changed Address',
                'amendment_reason' => '',
            ], $owner);
            $this->fail('Customer details changed without an amendment reason.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amendment_reason', $exception->errors());
        }
        $this->assertSame('Original Customer', $invoice->fresh()->customer_name);

        app(TaxInvoiceService::class)->void($invoice, 'Invoice was cancelled.', $owner);

        $this->expectException(ValidationException::class);
        app(TaxInvoiceService::class)->updateCustomerDetails($invoice->refresh(), [
            'customer_name' => 'Changed After Void',
            'customer_trn' => null,
            'customer_address' => 'Changed Address',
            'amendment_reason' => 'Attempted after void.',
        ], $owner);
    }

    public function test_permission_and_existing_record_scope_are_enforced_server_side(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $other = $this->user(EmployeeRole::Staff);
        $this->profile($owner);
        $mine = $this->invoice($staff, 'ORDER-MINE');
        $notMine = $this->invoice($other, 'ORDER-NOT-MINE');
        $data = [
            'customer_name' => 'Scoped Customer',
            'customer_trn' => null,
            'customer_address' => 'Scoped Address',
            'amendment_reason' => 'Correct scoped customer details.',
        ];

        try {
            app(TaxInvoiceService::class)->updateCustomerDetails($mine, $data, $staff);
            $this->fail('An employee without the dedicated permission amended an Invoice.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $this->allow($staff, InvoicePermission::EditCustomerDetails, $owner);
        app(TaxInvoiceService::class)->updateCustomerDetails($mine, $data, $staff);
        $this->assertSame('Scoped Customer', $mine->fresh()->customer_name);

        $this->expectException(AuthorizationException::class);
        app(TaxInvoiceService::class)->updateCustomerDetails($notMine, $data, $staff);
    }

    public function test_filament_actions_are_prefilled_authorized_and_hidden_for_void_invoices(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $this->profile($owner);
        $invoice = $this->invoice($staff);

        Livewire::actingAs($staff)->test(ListTaxInvoices::class)
            ->assertTableActionHidden('editCustomerDetails', $invoice);

        $this->allow($staff, InvoicePermission::EditCustomerDetails, $owner);

        Livewire::actingAs($staff)->test(ListTaxInvoices::class)
            ->assertTableActionVisible('editCustomerDetails', $invoice)
            ->mountTableAction('editCustomerDetails', $invoice)
            ->assertTableActionDataSet([
                'customer_name' => 'Original Customer',
                'customer_trn' => null,
                'customer_address' => 'Original Address',
            ])
            ->setTableActionData([
                'customer_name' => 'Modal Customer',
                'customer_trn' => 'TRN-UPDATED',
                'customer_address' => 'Modal Address',
                'amendment_reason' => 'Corrected through the controlled modal.',
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors()
            ->assertNotified('Customer details updated');

        $invoice->refresh();
        Livewire::actingAs($staff)
            ->test(ViewTaxInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionVisible('editCustomerDetails');

        app(TaxInvoiceService::class)->void($invoice, 'Void visibility test.', $owner);

        Livewire::actingAs($staff)->test(ListTaxInvoices::class)
            ->set('activeTab', 'all')
            ->assertTableActionHidden('editCustomerDetails', $invoice->refresh());

        Livewire::actingAs($staff)
            ->test(ViewTaxInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionHidden('editCustomerDetails');
    }

    public function test_internal_record_information_and_amendment_history_are_visible_only_inside_the_erp(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $owner->forceFill(['name' => 'Invoice Owner'])->save();
        $staff->forceFill(['name' => 'Amendment Employee'])->save();
        $this->profile($owner);
        $this->allow($staff, InvoicePermission::EditCustomerDetails, $owner);
        $invoice = $this->invoice($staff, 'ORDER-HISTORY');

        Livewire::actingAs($staff)
            ->test(ViewTaxInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertSee('Record Information')
            ->assertSee('Invoice Date')
            ->assertSee('Created At')
            ->assertSee('Created By')
            ->assertSee('Amendment Employee')
            ->assertSee('Last Customer Amendment At')
            ->assertSee('Last Amended By')
            ->assertSee('Amendment Count')
            ->assertActionHidden('viewAmendmentHistory');

        app(TaxInvoiceService::class)->updateCustomerDetails($invoice, [
            'customer_name' => 'History Customer LLC',
            'customer_trn' => 'TRN-HISTORY',
            'customer_address' => 'History Address',
            'amendment_reason' => 'Customer requested legal-name correction.',
        ], $staff);
        $invoice->refresh()->load('customerDetailAmendments.actor');

        Livewire::actingAs($staff)
            ->test(ViewTaxInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertSee('Record Information')
            ->assertSee('Amendment Employee')
            ->assertSee($invoice->customerDetailAmendments->first()->created_at->timezone(config('app.timezone'))->format('d M Y, h:i A'))
            ->assertActionVisible('viewAmendmentHistory');

        $historyTemplate = file_get_contents(dirname(__DIR__, 3).'/resources/views/filament/resources/tax-invoices/partials/customer-amendment-history.blade.php');
        $this->blade($historyTemplate, ['amendments' => $invoice->customerDetailAmendments])
            ->assertSee('Customer requested legal-name correction.')
            ->assertSee('Original Customer')
            ->assertSee('History Customer LLC')
            ->assertSee('TRN-HISTORY')
            ->assertSee('Original Address')
            ->assertSee('History Address')
            ->assertSee('Amendment Employee');

        $documentData = app(TaxInvoiceDocumentService::class)->printViewData($invoice);
        $documentHtml = view('invoices.tax-invoice', $documentData)->render();
        $this->assertStringNotContainsString('Record Information', $documentHtml);
        $this->assertStringNotContainsString('Last Customer Amendment At', $documentHtml);
        $this->assertStringNotContainsString('View Amendment History', $documentHtml);
        $this->assertStringNotContainsString('Customer requested legal-name correction.', $documentHtml);
        $this->assertStringNotContainsString('Amendment Employee', $documentHtml);

        $this->actingAs($staff)
            ->get(route('tax-invoices.pdf', ['invoice' => $invoice, 'print' => 1]))
            ->assertOk()
            ->assertDontSee('Record Information')
            ->assertDontSee('Customer requested legal-name correction.');
    }

    public function test_internal_record_information_keeps_existing_invoice_view_scope(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $other = $this->user(EmployeeRole::Staff);
        $this->profile($owner);
        $invoice = $this->invoice($other, 'ORDER-HISTORY-SCOPED');

        $this->actingAs($staff)
            ->get("/admin/tax-invoices/{$invoice->id}")
            ->assertForbidden();
    }

    private function invoice(User $actor, string $orderReference = 'ORDER-AMEND'): TaxInvoice
    {
        return app(TaxInvoiceService::class)->create([
            'customer_name' => 'Original Customer',
            'customer_address' => 'Original Address',
            'customer_trn' => null,
            'order_reference' => $orderReference,
            'invoice_date' => '2026-09-17',
            'idempotency_key' => (string) Str::uuid(),
            'items' => [[
                'description' => 'Laptop',
                'quantity' => 2,
                'unit_price_including_vat' => '525.00',
            ]],
        ], $actor);
    }

    private function allow(User $user, InvoicePermission $permission, User $owner): void
    {
        EmployeePermissionOverride::query()->create([
            'employee_id' => $user->employee->id,
            'permission_key' => $permission->value,
            'effect' => EmployeePermissionEffect::Allow,
            'granted_by_user_id' => $owner->id,
            'reason' => 'Focused Invoice customer amendment test.',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($user->employee->id);
    }

    private function profile(User $owner): void
    {
        app(CompanyProfileService::class)->save([
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
        ], $owner);
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create([
            'email' => $user->email,
            'status' => true,
        ]);

        return $user->refresh();
    }
}
