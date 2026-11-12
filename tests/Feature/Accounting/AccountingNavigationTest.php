<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountingPermission;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\ExpensePermission;
use App\Enums\OfficeFinancePermission;
use App\Filament\Pages\Finance\AccountingChartOfAccounts;
use App\Filament\Pages\Finance\AccountingGeneralLedger;
use App\Filament\Pages\Finance\AccountingHome;
use App\Filament\Pages\Finance\AccountingJournals;
use App\Filament\Pages\Finance\AccountingPeriods;
use App\Filament\Pages\Finance\AccountingTrialBalance;
use App\Models\Employee;
use App\Models\User;
use App\Services\Authorization\EmployeePermissionOverrideService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounting_pages_use_the_separate_group_and_requested_labels(): void
    {
        $pages = [
            AccountingHome::class => 'Accounting Home',
            AccountingChartOfAccounts::class => 'Chart of Accounts',
            AccountingJournals::class => 'General Journal',
            AccountingGeneralLedger::class => 'General Ledger',
            AccountingTrialBalance::class => 'Trial Balance',
            AccountingPeriods::class => 'Accounting Periods',
        ];

        foreach ($pages as $page => $label) {
            $this->assertSame('Accounting', $page::getNavigationGroup());
            $this->assertSame($label, $page::getNavigationLabel());
        }
    }

    public function test_expense_and_office_finance_access_do_not_expose_accounting_routes(): void
    {
        $this->withoutVite();
        $owner = $this->employee(EmployeeRole::Owner);
        $staff = $this->employee(EmployeeRole::Staff);
        $overrides = app(EmployeePermissionOverrideService::class);
        $overrides->change($staff, ExpensePermission::View->value, EmployeePermissionEffect::Allow, 'Finance sidebar scope test', $owner->user);
        $overrides->change($staff, OfficeFinancePermission::View->value, EmployeePermissionEffect::Allow, 'Finance sidebar scope test', $owner->user);

        $this->actingAs($staff->user);
        foreach ($this->accountingPages() as $page) {
            $this->assertFalse($page::shouldRegisterNavigation());
            $this->get($page::getUrl())->assertForbidden();
        }
    }

    public function test_granting_one_accounting_view_permission_exposes_only_that_page(): void
    {
        $this->withoutVite();
        $owner = $this->employee(EmployeeRole::Owner);
        $staff = $this->employee(EmployeeRole::Staff);
        app(EmployeePermissionOverrideService::class)->change(
            $staff,
            AccountingPermission::ViewChart->value,
            EmployeePermissionEffect::Allow,
            'Chart-only navigation test',
            $owner->user,
        );

        $this->actingAs($staff->user);
        foreach ($this->accountingPages() as $page) {
            $allowed = in_array($page, [AccountingHome::class, AccountingChartOfAccounts::class], true);
            $this->assertSame($allowed, $page::shouldRegisterNavigation());
            $response = $this->get($page::getUrl());
            $allowed ? $response->assertOk() : $response->assertForbidden();
        }

        $this->get(AccountingChartOfAccounts::getUrl())
            ->assertOk()
            ->assertDontSee('Create account')
            ->assertSee('Chart of Accounts')
            ->assertSee('Customer Center')
            ->assertDontSee('href="'.AccountingJournals::getUrl().'"', false)
            ->assertDontSee('href="'.AccountingGeneralLedger::getUrl().'"', false)
            ->assertDontSee('href="'.AccountingTrialBalance::getUrl().'"', false)
            ->assertDontSee('href="'.AccountingPeriods::getUrl().'"', false);
    }

    /** @return array<int, class-string> */
    private function accountingPages(): array
    {
        return [
            AccountingHome::class,
            AccountingChartOfAccounts::class,
            AccountingJournals::class,
            AccountingGeneralLedger::class,
            AccountingTrialBalance::class,
            AccountingPeriods::class,
        ];
    }

    private function employee(EmployeeRole $role): Employee
    {
        $user = User::factory()->create([
            'email' => fake()->unique()->userName().'@techpointzone.com',
        ]);

        return Employee::factory()->for($user)->create([
            'role' => $role,
            'status' => true,
            'email' => $user->email,
        ]);
    }
}
