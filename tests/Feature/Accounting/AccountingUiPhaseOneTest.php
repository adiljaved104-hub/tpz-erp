<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountingJournalStatus;
use App\Enums\AccountingPermission;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Filament\Pages\Finance\AccountingChartOfAccounts;
use App\Filament\Pages\Finance\AccountingHome;
use App\Filament\Pages\Finance\AccountingJournals;
use App\Models\AccountingBook;
use App\Models\AccountingJournal;
use App\Models\Employee;
use App\Models\User;
use App\Services\Authorization\EmployeePermissionOverrideService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class AccountingUiPhaseOneTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounting_home_shows_real_book_context_and_marks_unavailable_workflows(): void
    {
        $this->withoutVite();
        $owner = $this->employee(EmployeeRole::Owner);
        $book = AccountingBook::query()->firstOrFail();

        $response = $this->actingAs($owner)->get(AccountingHome::getUrl());

        $response->assertOk()
            ->assertSee($book->name)
            ->assertSee('Customers &amp; Receivables', false)
            ->assertSee('Vendors &amp; Payables', false)
            ->assertSee('Banking')
            ->assertSee('Inventory Accounting')
            ->assertSee('Not connected')
            ->assertSee('posting activation is off');
    }

    public function test_chart_of_accounts_can_create_a_valid_subaccount_using_existing_parent_relationship(): void
    {
        $this->withoutVite();
        $owner = $this->employee(EmployeeRole::Owner);
        $book = AccountingBook::query()->firstOrFail();
        $parent = $book->accounts()->where('number', '1000')->firstOrFail();

        $this->actingAs($owner);

        Livewire::test(AccountingChartOfAccounts::class)
            ->set('bookId', (string) $book->id)
            ->set('number', '1011')
            ->set('name', 'Phase One Test Subaccount')
            ->set('type', 'asset')
            ->set('parentId', (string) $parent->id)
            ->set('posting', true)
            ->call('saveAccount')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('accounting_accounts', [
            'accounting_book_id' => $book->id,
            'parent_id' => $parent->id,
            'number' => '1011',
            'name' => 'Phase One Test Subaccount',
            'is_posting' => true,
        ]);
    }

    public function test_general_journal_saves_a_balanced_draft_and_keeps_posting_disabled(): void
    {
        $this->withoutVite();
        $owner = $this->employee(EmployeeRole::Owner);
        $book = AccountingBook::query()->firstOrFail();
        $debit = $book->accounts()->where('number', '1100')->firstOrFail();
        $credit = $book->accounts()->where('number', '3000')->firstOrFail();

        $this->actingAs($owner);

        Livewire::test(AccountingJournals::class)
            ->set('bookId', (string) $book->id)
            ->set('postingDate', now()->toDateString())
            ->set('narration', 'Phase one UI balanced draft')
            ->set('lines', [
                ['account_id' => (string) $debit->id, 'debit' => '25.00', 'credit' => '', 'description' => 'Debit line'],
                ['account_id' => (string) $credit->id, 'debit' => '', 'credit' => '25.00', 'description' => 'Credit line'],
            ])
            ->call('saveDraft')
            ->assertHasNoErrors()
            ->assertSee('Posting disabled');

        $draft = AccountingJournal::query()->where('narration', 'Phase one UI balanced draft')->firstOrFail();
        $this->assertSame(AccountingJournalStatus::Draft, $draft->status);
        $this->assertFalse($book->refresh()->posting_enabled);
        $this->assertFalse(config('accounting.posting_activation_approved'));
    }

    public function test_journal_line_controls_keep_two_rows_and_calculate_totals_without_floats(): void
    {
        $this->withoutVite();
        $owner = $this->employee(EmployeeRole::Owner);
        $this->actingAs($owner);

        Livewire::test(AccountingJournals::class)
            ->set('lines.0.debit', '12.35')
            ->set('lines.1.credit', '12.35')
            ->assertSee('12.35')
            ->call('addLine')
            ->assertCount('lines', 3)
            ->call('removeLine', 2)
            ->assertCount('lines', 2)
            ->call('removeLine', 0)
            ->assertCount('lines', 2);
    }

    public function test_view_journals_does_not_grant_create_journal_controls(): void
    {
        $this->withoutVite();
        $owner = $this->employee(EmployeeRole::Owner);
        $staff = $this->employee(EmployeeRole::Staff);
        app(EmployeePermissionOverrideService::class)->change(
            $staff->employee,
            AccountingPermission::ViewJournals->value,
            EmployeePermissionEffect::Allow,
            'Journal view-only interface test',
            $owner,
        );

        $this->actingAs($staff);

        Livewire::test(AccountingJournals::class)
            ->assertSee('Draft creation is not available to your role')
            ->assertDontSee('New journal draft')
            ->assertDontSee('Save Draft');
    }

    private function employee(EmployeeRole $role): User
    {
        $user = User::factory()->create(['email' => Str::uuid().'@techpointzone.com']);
        Employee::factory()->for($user)->create(['role' => $role, 'status' => true, 'email' => $user->email]);

        return $user;
    }
}
