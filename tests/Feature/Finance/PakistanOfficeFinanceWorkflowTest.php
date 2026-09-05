<?php

namespace Tests\Feature\Finance;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\OfficeFinancePermission;
use App\Filament\Pages\Finance\PakistanOfficeAccounts;
use App\Filament\Pages\Finance\PakistanOfficeCashbook;
use App\Filament\Pages\Finance\PakistanOfficeExpenses;
use App\Filament\Pages\Finance\PakistanOfficeFinanceDashboard;
use App\Filament\Pages\Finance\PakistanOfficeFunding;
use App\Filament\Pages\Finance\PakistanOfficeLoans;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\User;
use App\Services\Authorization\OfficeFinanceAuthorization;
use App\Services\Finance\OfficeFinanceDashboardService;
use App\Services\Finance\OfficeFinanceService;
use App\Services\Reports\ReportCatalog;
use App\Services\Reports\ReportExportService;
use App\Services\Reports\ReportQueryService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class PakistanOfficeFinanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_posts_decimal_safe_funding_expense_and_adjustment_without_affecting_aed_expenses(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $service = app(OfficeFinanceService::class);
        $account = $service->createAccount(['name' => 'Pakistan Bank', 'account_type' => 'bank', 'active' => true], $owner);
        $funding = $service->postFunding($this->transaction($account->id, '75800.00') + [
            'funding_source' => 'Dubai Head Office', 'aed_amount' => '1000.00',
            'exchange_rate_pkr_per_aed' => '75.800000', 'fx_bank_charges_pkr' => '50.00',
        ], $owner);
        $expense = $service->postExpense($this->transaction($account->id, '800.25') + ['category' => 'office_rent'], $owner);
        $adjustment = $service->postAdjustment($this->transaction($account->id, '200.25') + ['direction' => 'out', 'adjustment_reason' => 'Controlled opening balance correction.'], $owner);

        $this->assertSame('75800.00', $funding->amount_pkr);
        $this->assertSame('75800.00', $funding->calculated_pkr_amount);
        $this->assertSame('75.800000', $funding->exchange_rate_pkr_per_aed);
        $this->assertSame('74799.50', app(OfficeFinanceDashboardService::class)->totalBalance());
        $this->assertDatabaseCount('expenses', 0);
        $this->assertSame('expense', $expense->transaction_type->value);
        $this->assertSame('adjustment', $adjustment->transaction_type->value);
        $this->assertDatabaseHas('activity_logs', ['event' => 'office_finance.transaction_posted', 'subject_type' => 'office_finance_transaction']);
    }

    public function test_employee_loan_and_repayments_are_atomic_independent_and_idempotent(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $employee = $this->user(EmployeeRole::Staff)->employee;
        $service = app(OfficeFinanceService::class);
        $account = $service->createAccount(['name' => 'Cash Drawer', 'account_type' => 'cash'], $owner);
        $service->postFunding($this->transaction($account->id, '10000.00') + ['funding_source' => 'Dubai', 'aed_amount' => '100.00', 'exchange_rate_pkr_per_aed' => '100.000000'], $owner);
        $loanKey = (string) Str::uuid();
        $loanData = $this->transaction($account->id, '3000.00', $loanKey) + ['employee_id' => $employee->id];
        $loan = $service->createLoan($loanData, $owner);
        $this->assertSame($loan->id, $service->createLoan($loanData, $owner)->id);
        $this->assertDatabaseCount('employee_loans', 1);
        $this->assertDatabaseCount('office_finance_transactions', 2);

        $repaymentKey = (string) Str::uuid();
        $repaymentData = $this->transaction($account->id, '1000.00', $repaymentKey);
        $repayment = $service->recordRepayment($loan, $repaymentData, $owner);
        $this->assertSame($repayment->id, $service->recordRepayment($loan, $repaymentData, $owner)->id);
        $this->assertSame('2000.00', $service->outstanding($loan));
        $this->assertSame('partially_repaid', $loan->fresh()->status->value);
        $this->expectValidation(fn () => $service->recordRepayment($loan, $this->transaction($account->id, '2000.01'), $owner), 'amount_pkr');
        $service->recordRepayment($loan, $this->transaction($account->id, '2000.00'), $owner);
        $this->assertSame('repaid', $loan->fresh()->status->value);
        $this->assertSame('10000.00', app(OfficeFinanceDashboardService::class)->totalBalance());
    }

    public function test_void_excludes_transaction_from_balance_and_preserves_history(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $service = app(OfficeFinanceService::class);
        $account = $service->createAccount(['name' => 'Petty Cash', 'account_type' => 'cash'], $owner);
        $expense = $service->postExpense($this->transaction($account->id, '250.00') + ['category' => 'grocery'], $owner);

        $service->void($expense, 'Duplicate receipt entered during daily posting.', $owner);

        $this->assertDatabaseCount('office_finance_transactions', 1);
        $this->assertSame('voided', $expense->fresh()->status);
        $this->assertSame('0.00', app(OfficeFinanceDashboardService::class)->totalBalance());
        $this->assertDatabaseHas('activity_logs', ['event' => 'office_finance.transaction_voided', 'subject_id' => $expense->id]);
    }

    public function test_permissions_default_to_owner_and_explicit_accountant_overrides_work(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $admin = $this->user(EmployeeRole::Admin);
        $staff = $this->user(EmployeeRole::Staff);
        $auth = app(OfficeFinanceAuthorization::class);
        $this->assertTrue($auth->allows($owner, OfficeFinancePermission::ViewBalances));
        $this->assertFalse($auth->allows($admin, OfficeFinancePermission::View));
        $this->assertFalse($auth->allows($staff, OfficeFinancePermission::View));
        $this->expectAuthorization(fn () => app(OfficeFinanceService::class)->postExpense($this->transaction(999, '1.00') + ['category' => 'grocery'], $staff));

        foreach ([OfficeFinancePermission::View, OfficeFinancePermission::Create] as $permission) {
            EmployeePermissionOverride::query()->create(['employee_id' => $staff->employee->id, 'permission_key' => $permission->value, 'effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $owner->id, 'reason' => 'Pakistan accountant workflow test']);
        }
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($staff->employee->id);
        $this->assertTrue($auth->allows($staff->refresh(), OfficeFinancePermission::View));
        $this->assertTrue($auth->allows($staff, OfficeFinancePermission::Create));
        $this->assertFalse($auth->allows($staff, OfficeFinancePermission::ViewBalances));
        $this->actingAs($staff);
        Livewire::test(PakistanOfficeCashbook::class)->assertOk();
        Livewire::test(PakistanOfficeExpenses::class)->assertOk();
        Livewire::test(PakistanOfficeFinanceDashboard::class)->assertOk()->assertDontSee('Available Office Balance');
        Livewire::test(PakistanOfficeFunding::class)->assertForbidden();
        Livewire::test(PakistanOfficeLoans::class)->assertForbidden();
        Livewire::test(PakistanOfficeAccounts::class)->assertForbidden();
    }

    public function test_owner_pages_reports_and_quickbooks_exports_are_registered_and_scoped(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $service = app(OfficeFinanceService::class);
        $account = $service->createAccount(['name' => 'Main Bank', 'account_type' => 'bank'], $owner);
        $service->postExpense($this->transaction($account->id, '125.50') + ['category' => 'utilities'], $owner);
        $this->actingAs($owner);
        foreach ([PakistanOfficeFinanceDashboard::class, PakistanOfficeCashbook::class, PakistanOfficeExpenses::class, PakistanOfficeFunding::class, PakistanOfficeLoans::class, PakistanOfficeAccounts::class] as $page) {
            Livewire::test($page)->assertOk();
        }
        $reports = app(ReportCatalog::class)->available($owner);
        foreach (['office_finance.cashbook', 'office_finance.expenses', 'office_finance.funding', 'office_finance.loans_outstanding', 'office_finance.loan_history', 'office_finance.quickbooks', 'office_finance.summary'] as $key) {
            $this->assertArrayHasKey($key, $reports);
        }
        $result = app(ReportQueryService::class)->run($owner, 'office_finance.quickbooks', ['from' => '2026-08-01', 'to' => '2026-08-31']);
        $this->assertSame(1, $result->totalRows);
        $this->assertContains('money_out', array_column($result->columns, 'key'));
        $csv = $this->stream(app(ReportExportService::class)->export($owner, 'office_finance.quickbooks', 'csv', ['from' => '2026-08-01', 'to' => '2026-08-31']));
        $this->assertStringContainsString('PKF-2026-', $csv);
        $this->assertStringContainsString('125.5', $csv);
        $xlsx = app(ReportExportService::class)->export($owner, 'office_finance.quickbooks', 'xlsx', ['from' => '2026-08-01', 'to' => '2026-08-31']);
        $this->assertNotEmpty(IOFactory::load($xlsx->getFile()->getPathname())->getActiveSheet()->toArray());
        $this->assertStringStartsWith('%PDF', (string) app(ReportExportService::class)->export($owner, 'office_finance.quickbooks', 'pdf', ['from' => '2026-08-01', 'to' => '2026-08-31'])->getContent());
    }

    public function test_dashboard_periods_weighted_rate_and_sql_level_sensitive_type_scope(): void
    {
        CarbonImmutable::setTestNow('2026-08-28 12:00:00');
        $owner = $this->user(EmployeeRole::Owner);
        $accountant = $this->user(EmployeeRole::Staff);
        $manager = $this->user(EmployeeRole::Manager);
        $service = app(OfficeFinanceService::class);
        $account = $service->createAccount(['name' => 'Funding Bank', 'account_type' => 'bank'], $owner);
        $service->postFunding($this->transaction($account->id, '7500.00') + ['funding_source' => 'Dubai A', 'aed_amount' => '100.00', 'exchange_rate_pkr_per_aed' => '75.000000'], $owner);
        $service->postFunding($this->transaction($account->id, '16000.00') + ['funding_source' => 'Dubai B', 'aed_amount' => '200.00', 'exchange_rate_pkr_per_aed' => '80.000000'], $owner);
        $service->postExpense($this->transaction($account->id, '500.00') + ['category' => 'utilities'], $owner);

        $dashboard = app(OfficeFinanceDashboardService::class);
        foreach (['today', 'yesterday', 'week', 'month', 'last_month'] as $period) {
            [$from, $to] = $dashboard->range($period);
            $this->assertTrue($to->greaterThanOrEqualTo($from));
        }
        [$from, $to] = $dashboard->range('custom', '2026-08-01', '2026-08-31');
        $this->assertSame('78.333333', $dashboard->metrics($owner, $from, $to)['summary']['effective_rate']);
        $this->assertFalse(app(OfficeFinanceAuthorization::class)->allows($manager, OfficeFinancePermission::View));

        foreach ([OfficeFinancePermission::View, OfficeFinancePermission::Create, OfficeFinancePermission::ViewAll, OfficeFinancePermission::Export] as $permission) {
            EmployeePermissionOverride::query()->create(['employee_id' => $accountant->employee->id, 'permission_key' => $permission->value, 'effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $owner->id, 'reason' => 'SQL projection regression']);
        }
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($accountant->employee->id);
        $accountantExpense = $service->postExpense($this->transaction($account->id, '25.00') + ['category' => 'grocery'], $accountant);
        $report = app(ReportQueryService::class)->run($accountant, 'office_finance.quickbooks', ['from' => '2026-08-01', 'to' => '2026-08-31']);
        $this->assertSame(2, $report->totalRows);
        $this->assertFalse($report->rows->contains(fn (array $row): bool => $row['type'] === 'Funding Received'));
        $this->assertTrue($report->rows->contains(fn (array $row): bool => $row['reference'] === $accountantExpense->reference));
    }

    public function test_finance_navigation_wording_and_pkr_expense_shortcut_reuse_the_existing_ledger(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $account = app(OfficeFinanceService::class)->createAccount([
            'name' => 'Pakistan Cash',
            'account_type' => 'cash',
            'active' => true,
        ], $owner);
        $this->actingAs($owner);

        $this->assertSame('Pakistan Office Finance', PakistanOfficeFinanceDashboard::getNavigationLabel());
        $this->assertSame('Pakistan Office Finance', PakistanOfficeCashbook::getNavigationParentItem());
        $this->assertSame('Pakistan Office Expenses', PakistanOfficeExpenses::getNavigationLabel());
        $this->assertSame('Pakistan Office Finance', PakistanOfficeExpenses::getNavigationParentItem());
        $this->assertSame('Pakistan Office Finance', PakistanOfficeFunding::getNavigationParentItem());
        $this->assertSame('Pakistan Office Finance', PakistanOfficeLoans::getNavigationParentItem());
        $this->assertSame('Pakistan Office Finance', PakistanOfficeAccounts::getNavigationParentItem());

        Livewire::test(PakistanOfficeFinanceDashboard::class)
            ->assertSee('PKR office funding, expenses, employee loans and cashbook')
            ->assertSee('Currency: PKR');

        Livewire::test(PakistanOfficeExpenses::class)
            ->assertSee('Use this for Pakistan office expenses recorded in PKR.')
            ->assertSee('Currency: PKR')
            ->assertSet('transactionType', 'expense')
            ->assertSet('typeFilter', 'expense')
            ->set('transactionDate', '2026-08-28')
            ->set('description', 'Office stationery')
            ->set('amountPkr', '750.00')
            ->set('accountId', (string) $account->id)
            ->set('category', 'office_supplies')
            ->call('postTransaction')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('office_finance_transactions', [
            'transaction_type' => 'expense',
            'amount_pkr' => 750,
        ]);
        $this->assertDatabaseCount('expenses', 0);
    }

    private function transaction(int $accountId, string $amount, ?string $key = null): array
    {
        return ['transaction_date' => '2026-08-28', 'description' => 'Pakistan Office finance test entry', 'amount_pkr' => $amount, 'office_finance_account_id' => $accountId, 'external_reference' => 'QB-REF', 'note' => null, 'idempotency_key' => $key ?? (string) Str::uuid()];
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);

        return $user->refresh();
    }

    private function expectValidation(\Closure $callback, string $field): void
    {
        try {
            $callback();
            $this->fail('ValidationException was expected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function expectAuthorization(\Closure $callback): void
    {
        try {
            $callback();
            $this->fail('AuthorizationException was expected.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }

    private function stream(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
