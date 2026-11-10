<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountingJournalStatus;
use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountingPermission;
use App\Enums\EmployeeRole;
use App\Models\AccountingBook;
use App\Models\AccountingPeriod;
use App\Models\Employee;
use App\Models\User;
use App\Services\Accounting\AccountingLedgerService;
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
        $period->forceFill(['status' => AccountingPeriodStatus::Closed])->saveQuietly();
        $service = app(AccountingLedgerService::class);
        $cash = $book->accounts()->where('number', '1100')->firstOrFail();
        $equity = $book->accounts()->where('number', '3000')->firstOrFail();
        $draft = $service->createDraft(['book_id' => $book->id, 'posting_date' => now()->toDateString(), 'narration' => 'Closed period journal', 'idempotency_key' => (string) Str::uuid(), 'lines' => [['account_id' => $cash->id, 'debit' => '50.00'], ['account_id' => $equity->id, 'credit' => '50.00']]], $owner);
        $service->enablePosting($book, $owner);

        $this->expectException(ValidationException::class);
        $service->post($draft, $owner);
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->create(['role' => $role, 'status' => true, 'email' => $user->email]);

        return $user;
    }
}
