<?php

namespace Tests\Feature\Invoices;

use App\Enums\EmployeeRole;
use App\Filament\Pages\Administration\InvoiceSettings;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\ReferenceSequence;
use App\Models\TaxInvoice;
use App\Models\User;
use App\Services\CompanyProfileService;
use App\Services\Invoices\InvoiceSettingsService;
use App\Services\Invoices\TaxInvoiceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class InvoiceSettingsSequenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_sequence_is_advanced_without_consuming_requested_number_and_is_audited(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->sequence(9159);

        app(InvoiceSettingsService::class)->save($this->settingsData(9623, 9159), $owner);

        $this->assertDatabaseHas('reference_sequences', ['key' => 'tax_invoice', 'next_value' => 9623]);
        $this->assertDatabaseHas('invoice_settings', ['id' => 1, 'starting_number' => 9623]);
        $log = ActivityLog::query()->where('event', 'invoice_settings.updated')->latest('id')->firstOrFail();
        $this->assertSame(9159, $log->properties['previous_next_number']);
        $this->assertSame(9623, $log->properties['new_next_number']);
        $this->assertSame($owner->id, $log->actor_user_id);
    }

    public function test_next_generated_invoice_uses_the_advanced_number(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->sequence(9159);
        app(InvoiceSettingsService::class)->save($this->settingsData(9623, 9159), $owner);
        $this->companyProfile($owner);

        $invoice = $this->invoice($owner);

        $this->assertSame('TP-INV 9623', $invoice->invoice_number);
        $this->assertDatabaseHas('reference_sequences', ['key' => 'tax_invoice', 'next_value' => 9624]);
    }

    public function test_sequence_cannot_move_backwards(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->sequence(9623);

        try {
            app(InvoiceSettingsService::class)->save($this->settingsData(9159, 9623), $owner);
            $this->fail('A backwards invoice sequence change should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('cannot be lower', $exception->errors()['next_invoice_number'][0]);
        }

        $this->assertDatabaseHas('reference_sequences', ['key' => 'tax_invoice', 'next_value' => 9623]);
        $this->assertDatabaseCount('invoice_settings', 0);
    }

    public function test_page_shows_sequence_validation_errors_on_the_next_number_field(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->sequence(9623);

        Livewire::actingAs($owner)->test(InvoiceSettings::class)
            ->set('nextInvoiceNumber', 9159)
            ->call('save')
            ->assertHasErrors(['nextInvoiceNumber']);

        $this->assertDatabaseHas('reference_sequences', ['key' => 'tax_invoice', 'next_value' => 9623]);
        $this->assertDatabaseCount('invoice_settings', 0);
    }

    public function test_an_issued_invoice_number_cannot_be_selected_again(): void
    {
        [$owner, $invoice] = $this->usedInvoiceAt(9623);

        $this->assertSame('issued', $invoice->status);
        $this->assertUsedNumberIsRejected($owner, 9623);
    }

    public function test_a_void_invoice_number_cannot_be_selected_again(): void
    {
        [$owner, $invoice] = $this->usedInvoiceAt(9623);
        app(TaxInvoiceService::class)->void($invoice, 'Focused sequence reuse test', $owner);

        $this->assertSame('void', $invoice->refresh()->status);
        $this->assertUsedNumberIsRejected($owner, 9623);
    }

    public function test_saving_unrelated_settings_does_not_change_the_sequence(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->sequence(9159);

        app(InvoiceSettingsService::class)->save($this->settingsData(9159, 9159, ['vat_rate' => '7.00']), $owner);

        $this->assertDatabaseHas('reference_sequences', ['key' => 'tax_invoice', 'next_value' => 9159]);
        $this->assertDatabaseHas('invoice_settings', ['id' => 1, 'vat_rate' => '7.00']);
    }

    public function test_same_value_save_is_idempotent(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->sequence(9623);
        $service = app(InvoiceSettingsService::class);

        $service->save($this->settingsData(9623, 9623), $owner);
        $service->save($this->settingsData(9623, 9623), $owner);

        $this->assertDatabaseHas('reference_sequences', ['key' => 'tax_invoice', 'next_value' => 9623]);
        $this->assertDatabaseCount('invoice_settings', 1);
    }

    public function test_stale_expected_number_is_rejected_without_overwriting_the_newer_sequence(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->sequence(9159);
        $service = app(InvoiceSettingsService::class);
        $service->save($this->settingsData(9160, 9159), $owner);

        try {
            $service->save($this->settingsData(9623, 9159), $owner);
            $this->fail('A stale invoice sequence change should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('has changed', $exception->errors()['next_invoice_number'][0]);
        }

        $this->assertDatabaseHas('reference_sequences', ['key' => 'tax_invoice', 'next_value' => 9160]);
        $this->assertDatabaseHas('invoice_settings', ['id' => 1, 'starting_number' => 9160]);
    }

    public function test_prefix_change_does_not_reset_the_numeric_sequence(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->sequence(9623);

        app(InvoiceSettingsService::class)->save($this->settingsData(9623, 9623, ['invoice_prefix' => 'NEW-INV']), $owner);

        $this->assertDatabaseHas('reference_sequences', ['key' => 'tax_invoice', 'next_value' => 9623]);
        $this->assertDatabaseHas('invoice_settings', ['id' => 1, 'invoice_prefix' => 'NEW-INV']);
    }

    public function test_page_reads_the_real_sequence_and_viewing_does_not_create_one(): void
    {
        $owner = $this->user(EmployeeRole::Owner);

        Livewire::actingAs($owner)->test(InvoiceSettings::class)
            ->assertSet('nextInvoiceNumber', 9153)
            ->assertSet('expectedNextInvoiceNumber', 9153)
            ->assertSee('Next Invoice Number');
        $this->assertDatabaseMissing('reference_sequences', ['key' => 'tax_invoice']);

        $this->sequence(9623);
        Livewire::actingAs($owner)->test(InvoiceSettings::class)
            ->assertSet('nextInvoiceNumber', 9623)
            ->assertSet('expectedNextInvoiceNumber', 9623);
        $this->assertDatabaseHas('reference_sequences', ['key' => 'tax_invoice', 'next_value' => 9623]);
    }

    public function test_existing_settings_authorization_remains_enforced(): void
    {
        $staff = $this->user(EmployeeRole::Staff);
        $this->actingAs($staff);

        $this->assertFalse(InvoiceSettings::canAccess());
        $this->expectException(AuthorizationException::class);
        app(InvoiceSettingsService::class)->save($this->settingsData(9623, 9153), $staff);
    }

    /** @return array{User, TaxInvoice} */
    private function usedInvoiceAt(int $number): array
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->sequence($number);
        app(InvoiceSettingsService::class)->save($this->settingsData($number, $number), $owner);
        $this->companyProfile($owner);
        $invoice = $this->invoice($owner);
        DB::table('reference_sequences')->where('key', 'tax_invoice')->update(['next_value' => $number]);

        return [$owner, $invoice];
    }

    private function assertUsedNumberIsRejected(User $owner, int $number): void
    {
        try {
            app(InvoiceSettingsService::class)->save($this->settingsData($number, $number), $owner);
            $this->fail('A used Tax Invoice number should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('already been used', $exception->errors()['next_invoice_number'][0]);
        }

        $this->assertDatabaseHas('reference_sequences', ['key' => 'tax_invoice', 'next_value' => $number]);
        $this->assertDatabaseCount('tax_invoices', 1);
    }

    private function sequence(int $next): void
    {
        ReferenceSequence::query()->create(['key' => 'tax_invoice', 'next_value' => $next]);
    }

    /** @return array<string, mixed> */
    private function settingsData(int $next, int $expected, array $overrides = []): array
    {
        return array_merge([
            'invoice_prefix' => 'TP-INV',
            'next_invoice_number' => $next,
            'expected_next_invoice_number' => $expected,
            'vat_rate' => '5.00',
            'terms_en' => InvoiceSettingsService::TERMS_EN,
            'terms_ar' => InvoiceSettingsService::TERMS_AR,
        ], $overrides);
    }

    private function invoice(User $actor): TaxInvoice
    {
        return app(TaxInvoiceService::class)->create([
            'customer_name' => 'Sequence Test Customer',
            'customer_address' => 'Dubai, UAE',
            'customer_trn' => null,
            'order_reference' => 'SEQUENCE-TEST',
            'invoice_date' => '2026-09-15',
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['description' => 'Laptop', 'quantity' => 1, 'unit_price_including_vat' => '105.00']],
        ], $actor);
    }

    private function companyProfile(User $owner): void
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
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'status' => true]);

        return $user->refresh();
    }
}
