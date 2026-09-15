<?php

namespace Tests\Feature\Invoices;

use App\Enums\EmployeeRole;
use App\Filament\Resources\TaxInvoices\Pages\CreateTaxInvoice;
use App\Filament\Resources\TaxInvoices\Pages\ListTaxInvoices;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\ReferenceSequence;
use App\Models\TaxInvoice;
use App\Models\User;
use App\Services\CompanyProfileService;
use App\Services\Invoices\TaxInvoiceDocumentService;
use App\Services\Invoices\TaxInvoiceService;
use App\Services\Invoices\TaxInvoiceZipService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class TaxInvoiceEmployeeWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_tabs_default_to_active_with_scoped_counts_and_keep_search_scoped_to_the_tab(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $other = $this->user(EmployeeRole::Staff);
        $this->profile($owner);

        $active = $this->invoice($staff, 'ACTIVE-SEARCH');
        $void = $this->invoice($staff, 'VOID-SEARCH');
        $hidden = $this->invoice($other, 'HIDDEN-SEARCH');
        app(TaxInvoiceService::class)->void($void, 'Customer order cancelled.', $owner);

        $component = Livewire::actingAs($staff)->test(ListTaxInvoices::class);
        $tabs = $component->instance()->getTabs();

        $component->assertSet('activeTab', 'active')
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$void, $hidden])
            ->searchTable('VOID-SEARCH')
            ->assertCanNotSeeTableRecords([$void]);

        $this->assertSame('1', $tabs['active']->getBadge());
        $this->assertSame('1', $tabs['void']->getBadge());
        $this->assertSame('2', $tabs['all']->getBadge());

        $component->set('tableSearch', '')
            ->set('activeTab', 'void')
            ->assertCanSeeTableRecords([$void])
            ->assertCanNotSeeTableRecords([$active, $hidden])
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$active, $void])
            ->assertCanNotSeeTableRecords([$hidden]);
    }

    public function test_created_at_column_and_inclusive_created_date_filters_work_with_status_tabs(): void
    {
        config()->set('app.timezone', 'Asia/Karachi');
        $owner = $this->user(EmployeeRole::Owner);
        $this->profile($owner);
        $fromOnly = $this->invoice($owner, 'CREATED-FROM');
        $sameDayVoid = $this->invoice($owner, 'CREATED-SAME-DAY');
        $outside = $this->invoice($owner, 'CREATED-OUTSIDE');

        DB::table('tax_invoices')->where('id', $fromOnly->id)->update(['created_at' => '2026-09-14 19:00:00']);
        DB::table('tax_invoices')->where('id', $sameDayVoid->id)->update(['created_at' => '2026-09-15 18:59:59']);
        DB::table('tax_invoices')->where('id', $outside->id)->update(['created_at' => '2026-09-15 19:00:00']);
        app(TaxInvoiceService::class)->void($sameDayVoid->refresh(), 'Created date tab test.', $owner);

        $fromComponent = Livewire::actingAs($owner)->test(ListTaxInvoices::class)
            ->assertTableColumnExists('invoice_date')
            ->assertTableColumnExists('created_at')
            ->set('activeTab', 'all')
            ->filterTable('created_at', ['created_from' => '2026-09-15']);
        $this->assertEqualsCanonicalizing(
            [$fromOnly->id, $sameDayVoid->id, $outside->id],
            $fromComponent->instance()->getFilteredTableQuery()->pluck('tax_invoices.id')->all(),
        );

        $toComponent = Livewire::actingAs($owner)->test(ListTaxInvoices::class)
            ->set('activeTab', 'all')
            ->filterTable('created_at', ['created_to' => '2026-09-15']);
        $this->assertEqualsCanonicalizing(
            [$fromOnly->id, $sameDayVoid->id],
            $toComponent->instance()->getFilteredTableQuery()->pluck('tax_invoices.id')->all(),
        );

        $sameDayComponent = Livewire::actingAs($owner)->test(ListTaxInvoices::class)
            ->set('activeTab', 'void')
            ->filterTable('created_at', ['created_from' => '2026-09-15', 'created_to' => '2026-09-15']);
        $this->assertSame(
            [$sameDayVoid->id],
            $sameDayComponent->instance()->getFilteredTableQuery()->pluck('tax_invoices.id')->all(),
        );
    }

    public function test_list_void_action_requires_reason_preserves_record_and_records_complete_audit(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->profile($owner);
        $invoice = $this->invoice($owner, 'VOID-LIST');
        $reference = $invoice->invoice_number;

        Livewire::actingAs($owner)->test(ListTaxInvoices::class)
            ->assertTableActionVisible('void', $invoice)
            ->callTableAction('void', $invoice, ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);

        $this->assertSame('issued', $invoice->fresh()->status);

        Livewire::actingAs($owner)->test(ListTaxInvoices::class)
            ->callTableAction('void', $invoice, ['reason' => 'Customer cancelled before delivery.'])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Invoice voided');

        $invoice->refresh();
        $log = ActivityLog::query()->where('event', 'tax_invoice.voided')->where('subject_id', $invoice->id)->sole();
        $this->assertSame('void', $invoice->status);
        $this->assertSame($reference, $invoice->invoice_number);
        $this->assertSame($owner->id, $invoice->voided_by_user_id);
        $this->assertSame('Customer cancelled before delivery.', $invoice->void_reason);
        $this->assertSame($reference, $log->properties['invoice_reference']);
        $this->assertSame('issued', $log->properties['previous_status']);
        $this->assertSame('void', $log->properties['new_status']);
        $this->assertSame($owner->id, $log->properties['actor_id']);
        $this->assertNotNull($log->created_at);
        $this->assertDatabaseHas('tax_invoices', ['id' => $invoice->id, 'status' => 'void']);

        Livewire::actingAs($owner)->test(ListTaxInvoices::class)
            ->set('activeTab', 'void')
            ->assertTableActionHidden('void', $invoice);
    }

    public function test_already_void_and_unauthorized_void_attempts_fail_closed(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $this->profile($owner);
        $invoice = $this->invoice($owner, 'VOID-CLOSED');
        app(TaxInvoiceService::class)->void($invoice, 'First and final void.', $owner);

        try {
            app(TaxInvoiceService::class)->void($invoice, 'Second void.', $owner);
            $this->fail('An already void Invoice was voided again.');
        } catch (ValidationException $exception) {
            $this->assertSame('This Invoice has already been voided.', $exception->errors()['reason'][0]);
        }

        $issued = $this->invoice($owner, 'VOID-DENIED');
        $this->expectException(AuthorizationException::class);
        app(TaxInvoiceService::class)->void($issued, 'Unauthorized attempt.', $staff);
    }

    public function test_authorized_bulk_zip_contains_active_and_void_invoice_pdfs_with_safe_names_and_no_mutation(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->profile($owner);
        $first = $this->invoice($owner, 'ZIP-A');
        $second = $this->invoice($owner, 'ZIP-B');
        app(TaxInvoiceService::class)->void($second, 'Include void PDF in ZIP.', $owner);
        $beforeSequence = ReferenceSequence::query()->findOrFail('tax_invoice')->next_value;
        $beforeRows = TaxInvoice::query()->orderBy('id')->get()->map->getAttributes()->all();

        $response = app(TaxInvoiceZipService::class)->download(new Collection([$first, $second]), $owner);
        $path = $response->getFile()->getPathname();
        $zip = new ZipArchive;

        try {
            $this->assertSame(true, $zip->open($path));
            $this->assertSame(2, $zip->numFiles);
            $this->assertNotFalse($zip->locateName('TP-INV-9153.pdf'));
            $this->assertNotFalse($zip->locateName('TP-INV-9154.pdf'));
            $this->assertStringStartsWith('%PDF-', (string) $zip->getFromName('TP-INV-9153.pdf'));
        } finally {
            $zip->close();
            File::delete($path);
        }

        $this->assertSame($beforeSequence, ReferenceSequence::query()->findOrFail('tax_invoice')->next_value);
        $this->assertSame($beforeRows, TaxInvoice::query()->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertDatabaseCount('tax_invoices', 2);
    }

    public function test_bulk_zip_enforces_limit_and_reauthorizes_every_selected_invoice(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $this->profile($owner);
        $mine = $this->invoice($staff, 'ZIP-MINE');
        $hidden = $this->invoice($owner, 'ZIP-HIDDEN');

        Livewire::actingAs($staff)->test(ListTaxInvoices::class)
            ->assertTableBulkActionExists('downloadSelectedPdfs');

        try {
            app(TaxInvoiceZipService::class)->download(new Collection([$mine, $hidden]), $staff);
            $this->fail('An unauthorized Invoice was included in a ZIP.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        try {
            app(TaxInvoiceZipService::class)->download(new Collection([$mine]), $staff, [$mine->id, $hidden->id]);
            $this->fail('A forged bulk selection was silently reduced to authorized records.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $this->expectException(ValidationException::class);
        app(TaxInvoiceZipService::class)->download(new Collection(array_fill(0, 51, $mine)), $staff);
    }

    public function test_filament_bulk_action_returns_one_zip_download_for_selected_authorized_invoices(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->profile($owner);
        $first = $this->invoice($owner, 'ZIP-ACTION-A');
        $second = $this->invoice($owner, 'ZIP-ACTION-B');

        Livewire::actingAs($owner)->test(ListTaxInvoices::class)
            ->callTableBulkAction('downloadSelectedPdfs', [$first, $second])
            ->assertHasNoTableActionErrors()
            ->assertFileDownloaded();

        $this->assertDatabaseCount('tax_invoices', 2);
        $this->assertDatabaseCount('tax_invoice_items', 2);
    }

    public function test_pdf_failure_aborts_bulk_zip_instead_of_returning_an_incomplete_archive(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->profile($owner);
        $invoice = $this->invoice($owner, 'ZIP-FAIL');
        $documents = Mockery::mock(TaxInvoiceDocumentService::class);
        $documents->shouldReceive('pdf')->once()->with(Mockery::on(fn (TaxInvoice $record): bool => $record->is($invoice)))->andThrow(new RuntimeException('Sensitive renderer detail'));
        $this->app->instance(TaxInvoiceDocumentService::class, $documents);

        try {
            app(TaxInvoiceZipService::class)->download(new Collection([$invoice]), $owner);
            $this->fail('An incomplete Invoice ZIP was returned.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($invoice->invoice_number, $exception->errors()['invoices'][0]);
            $this->assertStringNotContainsString('Sensitive renderer detail', $exception->errors()['invoices'][0]);
        }
    }

    public function test_save_download_and_new_creates_once_consumes_one_reference_and_clears_customer_data(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->profile($owner);
        $before = (int) (ReferenceSequence::query()->whereKey('tax_invoice')->value('next_value') ?? 9153);

        Livewire::actingAs($owner)->test(CreateTaxInvoice::class)
            ->fillForm($this->formData())
            ->call('saveDownloadAndNew')
            ->assertHasNoFormErrors()
            ->assertNotified('Invoice created')
            ->assertFileDownloaded('TP-INV-9153.pdf')
            ->assertSet('data.customer_name', null)
            ->assertSet('data.customer_address', null)
            ->assertSet('data.order_reference', null);

        $invoice = TaxInvoice::query()->sole();
        $this->assertSame('TP-INV 9153', $invoice->invoice_number);
        $this->assertDatabaseCount('tax_invoice_items', 1);
        $this->assertSame($before + 1, (int) ReferenceSequence::query()->findOrFail('tax_invoice')->next_value);
    }

    public function test_pdf_failure_after_create_preserves_invoice_and_number_and_shows_safe_retry_feedback(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->profile($owner);
        $before = (int) (ReferenceSequence::query()->whereKey('tax_invoice')->value('next_value') ?? 9153);
        Pdf::shouldReceive('loadView')->once()->andThrow(new RuntimeException('Renderer diagnostics must stay private'));

        Livewire::actingAs($owner)->test(CreateTaxInvoice::class)
            ->fillForm($this->formData())
            ->call('saveDownloadAndNew')
            ->assertHasNoFormErrors()
            ->assertNotified('Invoice saved, but PDF download failed')
            ->assertNoFileDownloaded()
            ->assertSet('data.customer_name', null);

        $invoice = TaxInvoice::query()->sole();
        $this->assertSame('TP-INV 9153', $invoice->invoice_number);
        $this->assertSame($before + 1, (int) ReferenceSequence::query()->findOrFail('tax_invoice')->next_value);
        $this->assertDatabaseCount('tax_invoice_items', 1);
    }

    public function test_secondary_save_still_creates_without_downloading_and_repeated_submission_is_idempotent(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->profile($owner);
        $component = Livewire::actingAs($owner)->test(CreateTaxInvoice::class)
            ->fillForm($this->formData())
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNoFileDownloaded();

        $component->call('create');

        $this->assertDatabaseCount('tax_invoices', 1);
        $this->assertDatabaseCount('tax_invoice_items', 1);
    }

    private function invoice(User $actor, string $orderReference): TaxInvoice
    {
        return app(TaxInvoiceService::class)->create([
            ...$this->formData(),
            'order_reference' => $orderReference,
            'idempotency_key' => (string) Str::uuid(),
        ], $actor);
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        return [
            'customer_name' => 'Walk-in Customer',
            'customer_address' => 'Dubai, UAE',
            'customer_trn' => null,
            'order_reference' => 'ORDER-UX',
            'invoice_date' => '2026-09-15',
            'items' => [[
                'description' => 'Laptop',
                'quantity' => 1,
                'unit_price_including_vat' => '530.00',
            ]],
        ];
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
        $user = User::factory()->create([
            'email' => fake()->unique()->userName().'@techpointzone.com',
        ]);
        Employee::factory()->for($user)->role($role)->create([
            'email' => $user->email,
            'status' => true,
        ]);

        return $user->refresh();
    }
}
