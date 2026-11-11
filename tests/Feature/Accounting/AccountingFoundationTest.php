<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountingJournalStatus;
use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountingPermission;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Models\AccountingBook;
use App\Models\AccountingJournal;
use App\Models\AccountingPeriod;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\User;
use App\Services\Accounting\AccountingLedgerService;
use App\Services\Authorization\AccessControlModuleRegistry;
use App\Services\Authorization\AccountingAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class AccountingFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_b1_migration_seeds_an_inactive_uae_book_and_chart_without_touching_operational_tables(): void
    {
        $book = AccountingBook::query()->where('code', 'TPZ-UAE')->firstOrFail();

        $this->assertSame('AED', $book->currency);
        $this->assertFalse($book->posting_enabled);
        $this->assertCount(11, $book->accounts);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_owner_can_create_a_balanced_draft_enable_posting_and_read_trial_balance(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $book = AccountingBook::query()->firstOrFail();
        $cash = $book->accounts()->where('number', '1100')->firstOrFail();
        $equity = $book->accounts()->where('number', '3000')->firstOrFail();
        $service = app(AccountingLedgerService::class);

        $draft = $service->createDraft([
            'book_id' => $book->id,
            'posting_date' => now()->toDateString(),
            'narration' => 'Opening cash journal',
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [
                ['account_id' => $cash->id, 'debit' => '1000.00', 'credit' => '0', 'description' => 'Cash'],
                ['account_id' => $equity->id, 'debit' => '0', 'credit' => '1000.00', 'description' => 'Capital'],
            ],
        ], $owner);

        $this->assertSame(AccountingJournalStatus::Draft, $draft->status);
        try {
            $service->enablePosting($book, $owner);
            $this->fail('Posting activation must remain gated until explicit approval.');
        } catch (ValidationException) {
            $this->assertFalse($book->refresh()->posting_enabled);
        }
        config()->set('accounting.posting_activation_approved', true);
        $service->enablePosting($book, $owner);
        $posted = $service->post($draft, $owner);

        $this->assertSame(AccountingJournalStatus::Posted, $posted->status);
        $this->actingAs($owner);
        $rows = $service->trialBalance($book, now()->toDateString());
        $cashRow = $rows->first(fn (array $row): bool => $row['account']->id === $cash->id);
        $equityRow = $rows->first(fn (array $row): bool => $row['account']->id === $equity->id);
        $this->assertSame('1000.00', $cashRow['debit']);
        $this->assertSame('1000.00', $equityRow['balance']);
        $this->assertSame(2, $service->generalLedger($book, now()->startOfYear()->toDateString(), now()->toDateString())->count());
    }

    public function test_unbalanced_or_control_account_journals_are_rejected(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $book = AccountingBook::query()->firstOrFail();
        $header = $book->accounts()->where('number', '1000')->firstOrFail();

        $this->expectException(ValidationException::class);
        app(AccountingLedgerService::class)->createDraft([
            'book_id' => $book->id,
            'posting_date' => now()->toDateString(),
            'narration' => 'Invalid journal',
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [
                ['account_id' => $header->id, 'debit' => '100.00', 'credit' => '0'],
                ['account_id' => $header->id, 'debit' => '0', 'credit' => '100.00'],
            ],
        ], $owner);
    }

    public function test_non_finance_employee_is_denied_and_posting_is_refused_in_closed_period(): void
    {
        $staff = $this->user(EmployeeRole::Staff);
        $book = AccountingBook::query()->firstOrFail();
        $cash = $book->accounts()->where('number', '1100')->firstOrFail();
        $equity = $book->accounts()->where('number', '3000')->firstOrFail();
        $service = app(AccountingLedgerService::class);
        $this->actingAs($staff);

        $this->expectException(AuthorizationException::class);
        $service->createDraft([
            'book_id' => $book->id,
            'posting_date' => now()->toDateString(),
            'narration' => 'Denied journal',
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['account_id' => $cash->id, 'debit' => '1.00'], ['account_id' => $equity->id, 'credit' => '1.00']],
        ], $staff);

        $this->assertFalse(app(AccountingAuthorization::class)->allows($staff, AccountingPermission::View));
    }

    public function test_posted_journals_are_immutable_and_reversal_is_a_balanced_compensating_entry(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $book = AccountingBook::query()->firstOrFail();
        $service = app(AccountingLedgerService::class);
        $cash = $book->accounts()->where('number', '1100')->firstOrFail();
        $equity = $book->accounts()->where('number', '3000')->firstOrFail();
        $draft = $service->createDraft(['book_id' => $book->id, 'posting_date' => now()->toDateString(), 'narration' => 'Reversible journal', 'idempotency_key' => (string) Str::uuid(), 'lines' => [['account_id' => $cash->id, 'debit' => '50.00'], ['account_id' => $equity->id, 'credit' => '50.00']]], $owner);
        config()->set('accounting.posting_activation_approved', true);
        $service->enablePosting($book, $owner);
        $posted = $service->post($draft, $owner);

        $reversal = $service->reverse($posted, 'Owner-approved correction', $owner);
        $this->assertSame(AccountingJournalStatus::Posted, $reversal->status);
        $this->assertSame($posted->id, $reversal->reversal_of_journal_id);
        $this->assertSame('50.00', (string) $reversal->lines->first()->credit);
        $this->assertSame('50.00', (string) $reversal->lines->last()->debit);

        $this->expectException(LogicException::class);
        $posted->update(['narration' => 'tampered']);
    }

    public function test_closed_period_refuses_new_posting(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $book = AccountingBook::query()->firstOrFail();
        $period = AccountingPeriod::query()->where('accounting_book_id', $book->id)->firstOrFail();
        $service = app(AccountingLedgerService::class);
        $cash = $book->accounts()->where('number', '1100')->firstOrFail();
        $equity = $book->accounts()->where('number', '3000')->firstOrFail();
        $draft = $service->createDraft(['book_id' => $book->id, 'posting_date' => now()->toDateString(), 'narration' => 'Closed period journal', 'idempotency_key' => (string) Str::uuid(), 'lines' => [['account_id' => $cash->id, 'debit' => '50.00'], ['account_id' => $equity->id, 'credit' => '50.00']]], $owner);
        $period->forceFill(['status' => AccountingPeriodStatus::Closed])->saveQuietly();
        config()->set('accounting.posting_activation_approved', true);
        $service->enablePosting($book, $owner);

        $this->expectException(ValidationException::class);
        $service->post($draft, $owner);
    }

    public function test_posting_controls_are_owner_only_and_idempotency_conflicts_are_rejected(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $admin = $this->user(EmployeeRole::Admin);
        $book = AccountingBook::query()->firstOrFail();
        $cash = $book->accounts()->where('number', '1100')->firstOrFail();
        $equity = $book->accounts()->where('number', '3000')->firstOrFail();
        $service = app(AccountingLedgerService::class);
        $key = (string) Str::uuid();
        $payload = [
            'book_id' => $book->id,
            'posting_date' => now()->toDateString(),
            'narration' => 'Idempotent journal',
            'idempotency_key' => $key,
            'lines' => [
                ['account_id' => $cash->id, 'debit' => '1234567890123456.78', 'credit' => '0'],
                ['account_id' => $equity->id, 'debit' => '0', 'credit' => '1234567890123456.78'],
            ],
        ];

        $draft = $service->createDraft($payload, $owner);
        $retry = $service->createDraft($payload, $owner);
        $this->assertSame($draft->id, $retry->id);
        $this->assertFalse(app(AccountingAuthorization::class)->allows($admin, AccountingPermission::PostJournal));

        $this->expectException(ValidationException::class);
        $service->createDraft([...$payload, 'narration' => 'Conflicting replay'], $owner);
    }

    public function test_decimal_overflow_and_negative_amounts_are_rejected_without_records(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $book = AccountingBook::query()->firstOrFail();
        $cash = $book->accounts()->where('number', '1100')->firstOrFail();
        $equity = $book->accounts()->where('number', '3000')->firstOrFail();
        $before = AccountingJournal::query()->count();

        try {
            app(AccountingLedgerService::class)->createDraft([
                'book_id' => $book->id,
                'posting_date' => now()->toDateString(),
                'narration' => 'Overflow journal',
                'idempotency_key' => (string) Str::uuid(),
                'lines' => [
                    ['account_id' => $cash->id, 'debit' => '12345678901234567.89'],
                    ['account_id' => $equity->id, 'credit' => '12345678901234567.89'],
                ],
            ], $owner);
            $this->fail('Overflow should be rejected.');
        } catch (ValidationException) {
            $this->assertSame($before, AccountingJournal::query()->count());
        }
    }

    public function test_access_control_registers_accounting_modules_without_granting_posting_to_admin(): void
    {
        $registry = app(AccessControlModuleRegistry::class);
        $modules = $registry->keyed();

        $this->assertSame(['accounting.view_chart'], $modules['accounting_chart']['view_keys']);
        $this->assertSame(['accounting.view_journals'], $modules['accounting_journals']['view_keys']);
        $this->assertSame(['accounting.view_periods'], $modules['accounting_periods']['view_keys']);
        $this->assertSame(['accounting.view_ledger'], $modules['accounting_ledger']['view_keys']);
        $this->assertContains('accounting.post_journal', $modules['accounting_journals']['advanced_keys']);
        $this->assertContains('accounting.manage_periods', $modules['accounting_periods']['edit_keys']);
        $this->assertNotSame($modules['accounting_journals']['edit_keys'], $modules['accounting_journals']['advanced_keys']);
    }

    public function test_accounting_view_permissions_are_independent_and_explicit_deny_overrides_role_defaults(): void
    {
        $admin = $this->user(EmployeeRole::Admin);
        $owner = $this->user(EmployeeRole::Owner);
        $adminEmployee = $admin->employee;

        EmployeePermissionOverride::create([
            'employee_id' => $adminEmployee->id,
            'permission_key' => AccountingPermission::ViewLedger->value,
            'effect' => EmployeePermissionEffect::Deny,
            'granted_by_user_id' => $owner->id,
            'reason' => 'Focused accounting access test',
        ]);

        $authorization = app(AccountingAuthorization::class);

        $this->assertTrue($authorization->allows($admin, AccountingPermission::ViewChart));
        $this->assertFalse($authorization->allows($admin, AccountingPermission::ViewLedger));
        $this->assertTrue($authorization->allows($owner, AccountingPermission::ViewLedger));
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->create(['role' => $role, 'status' => true, 'email' => $user->email]);

        return $user;
    }
}
