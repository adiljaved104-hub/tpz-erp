<?php

namespace Tests\Feature\Finance;

use App\Enums\AuthenticationOtpPurpose;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\ExpensePermission;
use App\Filament\Pages\RecoverLoginEmail;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\LoginEmailRecoveryRequest;
use App\Models\User;
use App\Notifications\AuthenticationOtpNotification;
use App\Services\AuthenticationOtpService;
use App\Services\ExpenseService;
use App\Services\LoginEmailRecoveryService;
use App\Services\Orders\WebSalesDashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ExpenseAndEmailRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_recovers_another_employee_only_after_new_email_otp_and_preserves_identity(): void
    {
        Notification::fake();
        $owner = $this->user(EmployeeRole::Owner, 'owner@techpointzone.com');
        $staff = $this->user(EmployeeRole::Staff, 'old@techpointzone.com');
        $original = [$staff->id, $staff->employee->id, $staff->employee->role, $staff->employee->team_id];
        $service = app(LoginEmailRecoveryService::class);

        $request = $service->start($owner, $staff, 'password', 'Employee confirmed loss of access to the previous mailbox.', '127.0.0.1');
        $request = $service->sendNewEmailCode($request, 'new@techpointzone.com', $owner, '127.0.0.1');

        $this->assertSame('old@techpointzone.com', $staff->fresh()->email);
        $code = $this->routedOtp('new@techpointzone.com', AuthenticationOtpPurpose::EmailRecoveryNew);
        $this->assertNull(app(AuthenticationOtpService::class)->verify($request->new_otp_challenge_id, AuthenticationOtpPurpose::EmailChangeNew, $code));
        $updated = $service->complete($request, $code, $owner);

        $this->assertSame('new@techpointzone.com', $updated->email);
        $this->assertSame($original, [$updated->id, $updated->employee->id, $updated->employee->role, $updated->employee->team_id]);
        $this->assertNotNull(LoginEmailRecoveryRequest::query()->sole()->completed_at);
        $this->assertDatabaseHas('activity_logs', ['event' => 'auth_security.login_email_recovery_completed', 'actor_user_id' => $owner->id, 'subject_id' => $staff->id]);
    }

    public function test_recovery_is_owner_only_excludes_owner_self_and_requires_owner_password(): void
    {
        Notification::fake();
        $owner = $this->user(EmployeeRole::Owner, 'owner@techpointzone.com');
        $target = $this->user(EmployeeRole::Staff, 'target@techpointzone.com');
        $service = app(LoginEmailRecoveryService::class);

        foreach ([EmployeeRole::Admin, EmployeeRole::Manager, EmployeeRole::Staff] as $role) {
            $this->expectAuthorization(fn () => $service->start($this->user($role), $target, 'password', 'A sufficiently detailed recovery reason.', null));
        }
        $this->expectAuthorization(fn () => $service->start($owner, $owner, 'password', 'A sufficiently detailed recovery reason.', null));

        try {
            $service->start($owner, $target, 'wrong-password', 'A sufficiently detailed recovery reason.', null);
            $this->fail('Owner password re-authentication should be required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ownerPassword', $exception->errors());
        }
    }

    public function test_owner_two_factor_domain_and_unique_email_rules_are_enforced(): void
    {
        Notification::fake();
        $owner = $this->user(EmployeeRole::Owner, 'owner@techpointzone.com');
        $owner->forceFill(['email_two_factor_enabled_at' => now()])->save();
        $target = $this->user(EmployeeRole::Staff, 'target@techpointzone.com');
        $this->user(EmployeeRole::Staff, 'used@techpointzone.com');
        $service = app(LoginEmailRecoveryService::class);

        $request = $service->start($owner, $target, 'password', 'Employee cannot access the old company mailbox.', null);
        $this->assertNull($request->owner_2fa_verified_at);
        $this->expectValidation(fn () => $service->sendNewEmailCode($request, 'new@techpointzone.com', $owner, null), 'newEmail');
        $request = $service->verifyOwnerTwoFactor($request, $this->userOtp($owner, AuthenticationOtpPurpose::TwoFactor), $owner);
        $this->expectValidation(fn () => $service->sendNewEmailCode($request, 'person@gmail.com', $owner, null), 'newEmail');
        $this->expectValidation(fn () => $service->sendNewEmailCode($request, 'used@techpointzone.com', $owner, null), 'newEmail');
        $this->assertSame('target@techpointzone.com', $target->fresh()->email);
    }

    public function test_recovery_action_is_visible_only_to_owner_for_another_employee(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $admin = $this->user(EmployeeRole::Admin);
        $target = $this->user(EmployeeRole::Staff);

        $this->actingAs($owner);
        Livewire::test(EditEmployee::class, ['record' => $target->employee->getRouteKey()])
            ->assertOk()
            ->assertSee('Recover Login Email')
            ->assertSee('Use recovery only when the employee cannot access the current mailbox.');
        $this->get(RecoverLoginEmail::getUrl(['employee' => $target->employee->id]))->assertOk();

        $this->actingAs($admin);
        Livewire::test(EditEmployee::class, ['record' => $target->employee->getRouteKey()])
            ->assertOk()
            ->assertDontSee('Recover Login Email');
        $this->get(RecoverLoginEmail::getUrl(['employee' => $target->employee->id]))->assertForbidden();
    }

    public function test_expenses_are_decimal_safe_audited_filterable_and_feed_owner_web_sales_net_profit(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'owner@techpointzone.com');
        $employee = $this->user(EmployeeRole::Staff)->employee;
        $service = app(ExpenseService::class);
        $web = $service->create($this->expenseData('100.25', 'web_sales', 'employee_salaries', $employee->id), $owner);
        $service->create($this->expenseData('50.10', 'marketplace'), $owner);
        $service->create($this->expenseData('25.00', 'web_sales', date: '2026-07-31'), $owner);

        $this->assertSame('100.25', $web->amount);
        $this->assertSame($employee->id, $web->employee_id);
        $this->assertDatabaseHas('activity_logs', ['event' => 'expense.created', 'subject_type' => 'expense', 'subject_id' => $web->id]);

        $metrics = app(WebSalesDashboardService::class)->metrics(
            $owner,
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-08-31'),
        );
        $this->assertSame('100.25', $metrics['summary']['operating_expenses']);
        $this->assertSame('-100.25', $metrics['summary']['net_profit']);
        $this->assertCount(1, $metrics['expense_breakdown']);

        $service->void($web, 'Entered against the wrong accounting period.', $owner);
        $this->assertNotNull($web->fresh()->voided_at);
        $this->assertSame('0.00', app(WebSalesDashboardService::class)->metrics($owner, CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31'))['summary']['operating_expenses']);
    }

    public function test_expense_permissions_and_sql_amount_omission_are_enforced(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $admin = $this->user(EmployeeRole::Admin);
        $staff = $this->user(EmployeeRole::Staff);
        $this->actingAs($owner);
        Livewire::test(ListExpenses::class)->assertOk();
        Livewire::test(CreateExpense::class)->assertOk();

        foreach ([$admin, $staff] as $user) {
            $this->actingAs($user);
            Livewire::test(ListExpenses::class)->assertForbidden();
        }

        EmployeePermissionOverride::query()->create([
            'employee_id' => $admin->employee->id,
            'permission_key' => ExpensePermission::View->value,
            'effect' => EmployeePermissionEffect::Allow,
            'granted_by_user_id' => $owner->id,
            'reason' => 'Read-only expense access test',
        ]);
        $this->actingAs($admin->refresh());
        $sql = strtolower(ExpenseResource::getEloquentQuery()->toSql());
        $this->assertStringNotContainsString('amount', $sql);
        $this->assertFalse(ExpenseResource::canCreate());
        $this->assertNull(app(WebSalesDashboardService::class)->metrics($admin, CarbonImmutable::today(), CarbonImmutable::today())['summary']['net_profit']);

        $this->expectValidation(fn () => app(ExpenseService::class)->create($this->expenseData('0'), $owner), 'amount');
    }

    public function test_aed_business_expense_wording_is_clear_without_changing_its_currency_or_permissions(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->actingAs($owner);

        $this->assertSame('Business Expenses (AED)', ExpenseResource::getNavigationLabel());
        $this->assertSame('AED', ExpenseResource::getNavigationBadge());

        Livewire::test(ListExpenses::class)
            ->assertOk()
            ->assertSee('UAE / Web Sales / Marketplace operating expenses')
            ->assertSee('Currency: AED')
            ->assertSee('New Business Expense');

        Livewire::test(CreateExpense::class)
            ->assertOk()
            ->assertSee('Use this for UAE/Web Sales/Marketplace business expenses.')
            ->assertSee('Currency: AED');
    }

    private function expenseData(string $amount, string $center = 'web_sales', string $category = 'advertising', ?int $employeeId = null, string $date = '2026-08-15'): array
    {
        return ['expense_date' => $date, 'category' => $category, 'description' => 'Focused operational expense', 'amount' => $amount, 'cost_center' => $center, 'employee_id' => $employeeId, 'reference_note' => 'REF-1'];
    }

    private function user(EmployeeRole $role, ?string $email = null): User
    {
        $user = User::factory()->create(['email' => $email ?? fake()->unique()->safeEmail(), 'password' => 'password']);
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);

        return $user->refresh();
    }

    private function userOtp(User $user, AuthenticationOtpPurpose $purpose): string
    {
        $code = '';
        Notification::assertSentTo($user, AuthenticationOtpNotification::class, function (AuthenticationOtpNotification $notification) use ($purpose, &$code): bool {
            if ($notification->purpose !== $purpose) {
                return false;
            }
            $code = $notification->code;

            return true;
        });

        return $code;
    }

    private function routedOtp(string $email, AuthenticationOtpPurpose $purpose): string
    {
        $code = '';
        Notification::assertSentOnDemand(AuthenticationOtpNotification::class, function (AuthenticationOtpNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, $purpose, &$code): bool {
            if ($notification->purpose !== $purpose || $notifiable->routeNotificationFor('mail') !== $email) {
                return false;
            }
            $code = $notification->code;

            return true;
        });

        return $code;
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

    private function expectValidation(\Closure $callback, string $field): void
    {
        try {
            $callback();
            $this->fail('ValidationException was expected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
