<?php

namespace Tests\Feature\Invoices;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\InvoicePermission;
use App\Filament\Pages\Administration\InvoiceSettings as SettingsPage;
use App\Models\CompanyProfile;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\InvoiceSetting;
use App\Models\TaxInvoice;
use App\Models\User;
use App\Services\Invoices\InvoiceFooterBrandingService;
use App\Services\Invoices\InvoiceSettingsService;
use App\Services\Invoices\TaxInvoiceDocumentService;
use App\Services\Invoices\TaxInvoiceService;
use App\Support\ArabicPdfText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InvoiceFooterBrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_nullable_settings_default_to_no_claims_and_old_invoices_do_not_inherit_them(): void
    {
        $owner = $this->user();
        $old = $this->invoice($owner);
        $snapshot = $old->seller_snapshot;
        unset($snapshot['footer_branding']);
        DB::table('tax_invoices')->where('id', $old->id)->update(['seller_snapshot' => json_encode($snapshot)]);
        $before = $old->fresh()->getRawOriginal();
        $this->assertTrue(Schema::hasColumn('invoice_settings', 'footer_branding'));
        $defaults = app(InvoiceFooterBrandingService::class)->defaults();
        foreach ($defaults['entries'] as $entry) {
            $this->assertFalse($entry['enabled']);
            $this->assertNull($entry['approved_by_user_id']);
            $this->assertSame('', $entry['caption']);
        }
        $this->enable($owner);
        $data = app(TaxInvoiceDocumentService::class)->printViewData($old->fresh());
        $this->assertSame([], $data['footerBranding']['entries']);
        $this->assertStringNotContainsString('<div class="branding-strip">', view('invoices.tax-invoice', $data)->render());
        $this->assertSame($before, $old->fresh()->getRawOriginal());
    }

    public function test_owner_approval_is_explicit_audited_and_new_invoices_snapshot_private_assets_and_statements(): void
    {
        $owner = $this->user();
        $this->enable($owner);
        $invoice = $this->invoice($owner);
        $data = app(TaxInvoiceDocumentService::class)->printViewData($invoice);
        $this->assertCount(7, $data['footerBranding']['entries']);
        $this->assertSame(array_keys(InvoiceFooterBrandingService::BRANDS), array_column($data['footerBranding']['entries'], 'key'));
        $this->assertSame('Verified sample certification statement.', $data['footerBranding']['statement_en']);
        $this->assertSame('بيان اعتماد اختباري معتمد.', $data['footerBranding']['statement_ar']);
        $configuration = InvoiceSetting::query()->sole()->footer_branding;
        $this->assertSame($owner->id, $configuration['entries']['iso']['approved_by_user_id']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'invoice_settings.footer_branding_updated', 'actor_user_id' => $owner->id]);
        foreach ($configuration['entries'] as $entry) {
            // Windows does not expose POSIX visibility bits. Linux CI verifies
            // private modes as well as the non-public storage namespace.
            if (DIRECTORY_SEPARATOR === '/') {
                $this->assertSame('private', Storage::disk('local')->getVisibility($entry['asset']['path']));
            }
            $this->assertStringNotContainsString(Storage::disk('public')->path(''), Storage::disk('local')->path($entry['asset']['path']));
            $this->assertStringStartsWith('invoice-footer/', $entry['asset']['path']);
        }
        $html = view('invoices.tax-invoice', $data)->render();
        $this->assertStringContainsString('<div class="branding-strip">', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringNotContainsString('Proof reference not for printing', $html);
        $this->assertSame('105.00', $invoice->fresh()->grand_total);
    }

    public function test_admin_can_upload_drafts_but_cannot_self_approve_or_forge_approval_metadata(): void
    {
        $admin = $this->user(EmployeeRole::Admin);
        $data = $this->input();
        $data['entries']['dell']['caption'] = 'Reviewed caption';
        $data['entries']['dell']['approved_by_user_id'] = 1;
        $data['entries']['dell']['approved_at'] = now()->toIso8601String();
        $settings = app(InvoiceSettingsService::class)->saveFooterBranding($data, ['dell' => $this->logo()], $admin);
        $this->assertNull($settings->footer_branding['entries']['dell']['approved_by_user_id']);
        $data['entries']['dell']['approve'] = true;
        $this->expectException(HttpException::class);
        app(InvoiceSettingsService::class)->saveFooterBranding($data, [], $admin);
    }

    public function test_employee_with_settings_manage_grant_cannot_manage_footer_branding(): void
    {
        $owner = $this->user();
        $staff = $this->user(EmployeeRole::Staff);
        EmployeePermissionOverride::query()->create(['employee_id' => $staff->employee->id, 'permission_key' => InvoicePermission::SettingsManage->value, 'effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $owner->id, 'reason' => 'Test existing permission independence']);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($staff->employee->id);
        $this->assertFalse(app(InvoiceFooterBrandingService::class)->canManage($staff));
        $this->expectException(HttpException::class);
        app(InvoiceSettingsService::class)->saveFooterBranding($this->input(), [], $staff);
    }

    public function test_explicit_admin_deny_is_respected(): void
    {
        $owner = $this->user();
        $admin = $this->user(EmployeeRole::Admin);
        EmployeePermissionOverride::query()->create(['employee_id' => $admin->employee->id, 'permission_key' => InvoicePermission::SettingsManage->value, 'effect' => EmployeePermissionEffect::Deny, 'granted_by_user_id' => $owner->id, 'reason' => 'Focused footer deny test']);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($admin->employee->id);
        $this->assertFalse(app(InvoiceFooterBrandingService::class)->canManage($admin));
    }

    public function test_owner_approval_requires_proof_and_an_upload_and_enabling_unapproved_artwork_is_rejected(): void
    {
        $owner = $this->user();
        foreach (['no_approval', 'no_proof', 'no_art'] as $case) {
            $data = $this->input();
            $data['entries']['iso'] = array_replace($data['entries']['iso'], ['enabled' => true, 'caption' => 'Reviewed caption', 'approve' => $case !== 'no_approval', 'evidence' => $case === 'no_proof' ? '' : 'Verified proof']);
            try {
                app(InvoiceSettingsService::class)->saveFooterBranding($data, $case === 'no_art' ? [] : ['iso' => $this->logo()], $owner);
                $this->fail('Invalid approval was accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->assertDatabaseCount('invoice_settings', 0);
    }

    public function test_caption_or_logo_changes_require_new_owner_approval_and_never_change_old_invoice_output(): void
    {
        $owner = $this->user();
        $this->enable($owner);
        $invoice = $this->invoice($owner)->fresh();
        $documents = app(TaxInvoiceDocumentService::class);
        $old = $documents->printViewData($invoice)['footerBranding'];
        $data = app(InvoiceFooterBrandingService::class)->defaults(InvoiceSetting::query()->sole()->footer_branding);
        $data['entries']['dell']['caption'] = 'Different reviewed relationship';
        $data['entries']['dell']['enabled'] = false;
        $settings = app(InvoiceSettingsService::class)->saveFooterBranding($data, ['dell' => $this->logo()], $owner);
        $this->assertNull($settings->footer_branding['entries']['dell']['approved_by_user_id']);
        $this->assertSame($old, $documents->printViewData($invoice->fresh())['footerBranding']);
        $this->assertCount(6, app(InvoiceFooterBrandingService::class)->document($settings->footer_branding)['entries']);
        foreach ($invoice->seller_snapshot['footer_branding']['entries'] as $entry) {
            Storage::disk('local')->assertExists($entry['asset']['path']);
        }
    }

    public function test_admin_can_disable_and_reenable_unchanged_approved_entries_but_not_change_a_live_claim(): void
    {
        $owner = $this->user();
        $this->enable($owner);
        $admin = $this->user(EmployeeRole::Admin);
        $data = app(InvoiceFooterBrandingService::class)->defaults(InvoiceSetting::query()->sole()->footer_branding);
        $data['entries']['dell']['enabled'] = false;
        $saved = app(InvoiceSettingsService::class)->saveFooterBranding($data, [], $admin);
        $data = app(InvoiceFooterBrandingService::class)->defaults($saved->footer_branding);
        $data['entries']['dell']['enabled'] = true;
        $saved = app(InvoiceSettingsService::class)->saveFooterBranding($data, [], $admin);
        $this->assertSame($owner->id, $saved->footer_branding['entries']['dell']['approved_by_user_id']);
        $data['entries']['dell']['caption'] = 'Unauthorized claim change';
        $this->expectException(ValidationException::class);
        app(InvoiceSettingsService::class)->saveFooterBranding($data, [], $admin);
    }

    public function test_remote_and_traversal_paths_and_changed_image_bytes_are_never_read(): void
    {
        $service = app(InvoiceFooterBrandingService::class);
        foreach (['https://example.test/logo.png', '../secret.png', 'file:///etc/passwd', 'company-profile/logo.png'] as $path) {
            $this->assertNull($service->image(['path' => $path]));
        }
        $owner = $this->user();
        $this->enable($owner);
        $configuration = InvoiceSetting::query()->sole()->footer_branding;
        Storage::disk('local')->put($configuration['entries']['iso']['asset']['path'], 'not the approved bytes');
        $rendered = $service->document($configuration);
        $this->assertNotContains('iso', array_column($rendered['entries'], 'key'));
        $this->assertSame('', $rendered['statement_en']);
    }

    public function test_bad_files_and_excessive_captions_are_rejected_and_generic_settings_save_cannot_bypass_approvals(): void
    {
        $owner = $this->user();
        foreach ([UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'), UploadedFile::fake()->create('huge.png', 2049, 'image/png')] as $upload) {
            try {
                app(InvoiceSettingsService::class)->saveFooterBranding($this->input(), ['iso' => $upload], $owner);
                $this->fail('Invalid artwork was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('uploads.iso', $exception->errors());
            }
        }
        $tooLong = $this->input();
        $tooLong['entries']['iso']['caption'] = str_repeat('a', 81);
        try {
            app(InvoiceSettingsService::class)->saveFooterBranding($tooLong, [], $owner);
            $this->fail('An excessive caption was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('entries.iso.caption', $exception->errors());
        }
        $this->expectException(ValidationException::class);
        app(InvoiceSettingsService::class)->save(['footer_branding' => ['enabled' => true]], $owner);
    }

    public function test_owner_can_revoke_approval_reorder_entries_and_disable_statements_without_changing_issued_invoices(): void
    {
        $owner = $this->user();
        $this->enable($owner);
        $invoice = $this->invoice($owner);
        $data = app(InvoiceFooterBrandingService::class)->defaults(InvoiceSetting::query()->sole()->footer_branding);
        $data['entries']['dell']['enabled'] = false;
        $data['entries']['dell']['revoke_approval'] = true;
        $data['entries']['msi']['order'] = 1;
        $data['entries']['iso']['order'] = 7;
        $data['statement']['enabled'] = false;
        $saved = app(InvoiceSettingsService::class)->saveFooterBranding($data, [], $owner);
        $this->assertNull($saved->footer_branding['entries']['dell']['approved_by_user_id']);
        $view = app(InvoiceFooterBrandingService::class)->document($saved->footer_branding);
        $this->assertSame('msi', $view['entries'][0]['key']);
        $this->assertSame('', $view['statement_ar']);
        $this->assertCount(7, app(TaxInvoiceDocumentService::class)->printViewData($invoice)['footerBranding']['entries']);
    }

    public function test_certification_statement_requires_enabled_iso_and_preserves_latin_identifier_in_arabic_pdf(): void
    {
        $owner = $this->user();
        $data = $this->input();
        $data['statement'] = array_replace($data['statement'], ['enabled' => true, 'en' => 'Certification statement.', 'ar' => 'شهادة ISO 9001:2015 معتمدة.', 'evidence' => 'Test proof', 'approve' => true]);
        try {
            app(InvoiceSettingsService::class)->saveFooterBranding($data, [], $owner);
            $this->fail('Statement without approved ISO artwork was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('statement.enabled', $exception->errors());
        }
        $shaped = app(ArabicPdfText::class)->forDompdf($data['statement']['ar']);
        $this->assertStringContainsString('ISO 9001:2015', app(InvoiceFooterBrandingService::class)->preserveLatinRuns($shaped));
    }

    public function test_livewire_upload_save_and_reopen_keep_invoice_terms_and_numbering_unchanged(): void
    {
        $owner = $this->user();
        $this->invoice($owner);
        $settings = app(InvoiceSettingsService::class)->settings();
        $unchanged = $settings->only(['invoice_prefix', 'starting_number', 'vat_rate', 'terms_en', 'terms_ar', 'renewed_terms_en', 'renewed_terms_ar']);
        $data = $this->input();
        $data['entries']['iso'] = array_replace($data['entries']['iso'], ['enabled' => true, 'caption' => 'Verified sample caption', 'evidence' => 'Test proof', 'approve' => true]);
        Livewire::actingAs($owner)->test(SettingsPage::class)
            ->assertSee('Footer Branding &amp; Certification', false)
            ->set('footerBranding', $data)->set('footerUploads.iso', $this->logo())
            ->call('saveFooterBranding')->assertHasNoErrors()->assertSet('footerUploads', []);
        Livewire::actingAs($owner)->test(SettingsPage::class)
            ->assertSet('footerBranding.entries.iso.enabled', true)
            ->assertSet('footerBranding.entries.iso.approve', false);
        $this->assertSame($unchanged, app(InvoiceSettingsService::class)->settings()->only(array_keys($unchanged)));
        Livewire::actingAs($this->user(EmployeeRole::Staff))->test(SettingsPage::class)->assertForbidden();
    }

    public function test_short_and_long_a4_pdfs_keep_logos_and_captions_together_on_the_last_page(): void
    {
        $owner = $this->user();
        $this->enable($owner);
        $documents = app(TaxInvoiceDocumentService::class);
        foreach ([1, 12] as $count) {
            $invoice = $this->invoice($owner, $count);
            $data = $documents->printViewData($invoice);
            $html = view('invoices.tax-invoice', $data)->render();
            $this->assertSame(1, substr_count($html, '<div class="branding-strip">'));
            $this->assertLessThan(strpos($html, '<div class="branding-strip">'), strpos($html, '<div class="terms">'));
            $this->assertStringContainsString('page-break-inside: avoid', $html);
            $pdf = $documents->pdf($invoice);
            $pdf->render();
            $pages = $pdf->getDomPDF()->getCanvas()->get_page_count();
            if ($count === 1) {
                $this->assertSame(1, $pages);
            } else {
                $this->assertGreaterThan(1, $pages);
            }
            $this->assertStringStartsWith('%PDF-', $pdf->output());
        }
    }

    private function input(): array
    {
        return app(InvoiceFooterBrandingService::class)->defaults();
    }

    private function enable(User $owner): void
    {
        $data = $this->input();
        $uploads = [];
        foreach (InvoiceFooterBrandingService::BRANDS as $key => $label) {
            $data['entries'][$key] = array_replace($data['entries'][$key], ['enabled' => true, 'caption' => 'Verified '.$label.' caption', 'evidence' => 'Proof reference not for printing', 'approve' => true]);
            $uploads[$key] = $this->logo();
        }
        $data['statement'] = array_replace($data['statement'], ['enabled' => true, 'en' => 'Verified sample certification statement.', 'ar' => 'بيان اعتماد اختباري معتمد.', 'evidence' => 'Test proof', 'approve' => true]);
        app(InvoiceSettingsService::class)->saveFooterBranding($data, $uploads, $owner);
    }

    private function logo(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('review.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII='));
    }

    private function user(EmployeeRole $role = EmployeeRole::Owner): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['status' => true]);

        return $user->refresh();
    }

    private function invoice(User $owner, int $count = 1): TaxInvoice
    {
        CompanyProfile::query()->firstOrCreate(['id' => 1], ['company_name_en' => 'Review company', 'trn' => '100000000000001']);

        return app(TaxInvoiceService::class)->create([
            'customer_name' => 'Review customer', 'customer_address' => 'Dubai', 'order_reference' => 'REVIEW-'.Str::uuid(),
            'invoice_date' => today()->toDateString(), 'idempotency_key' => (string) Str::uuid(),
            'items' => array_fill(0, $count, ['description' => $count === 1 ? 'Monitor' : str_repeat('Long laptop specification with memory storage processor and display. ', 5), 'quantity' => 1, 'unit_price_including_vat' => '105.00']),
        ], $owner);
    }
}
