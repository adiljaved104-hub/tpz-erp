<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountingPermission;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\ExpensePermission;
use App\Filament\Pages\Finance\AccountingHome;
use App\Filament\Pages\Finance\SupplierPayables;
use App\Models\AccountingBook;
use App\Models\Employee;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\AccountsPayableService;
use App\Services\Authorization\EmployeePermissionOverrideService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class SupplierPayablesUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_payables_uses_accounting_workspace_and_permission_controlled_navigation(): void
    {
        $this->withoutVite();
        $owner = $this->employee(EmployeeRole::Owner);

        $this->actingAs($owner)->get(SupplierPayables::getUrl())
            ->assertOk()
            ->assertSee('tpz-accounting-workspace', false)
            ->assertSee('tpz-accounting-sidebar', false)
            ->assertSee('tpz-accounting-tabs', false)
            ->assertSee('Supplier Payables')
            ->assertSee('aria-label="Accounting navigation"', false)
            ->assertDontSee('Payment ID')
            ->assertDontSee('Bill ID')
            ->assertDontSee('Supplier Bill</span><small>Preview', false)
            ->assertSee('do not post journals');
    }

    public function test_finance_only_employee_cannot_open_supplier_payables(): void
    {
        $this->withoutVite();
        $owner = $this->employee(EmployeeRole::Owner);
        $staff = $this->employee(EmployeeRole::Staff);

        app(EmployeePermissionOverrideService::class)->change(
            $staff->employee,
            ExpensePermission::View->value,
            EmployeePermissionEffect::Allow,
            'Verify Finance-only access remains separated from Accounting.',
            $owner,
        );

        $this->actingAs($staff)->get(SupplierPayables::getUrl())->assertForbidden();
        $this->assertFalse(SupplierPayables::shouldRegisterNavigation());
        $this->assertFalse(AccountingHome::shouldRegisterNavigation());
    }

    public function test_payables_permission_exposes_the_real_link_without_granting_other_accounting_pages(): void
    {
        $this->withoutVite();
        $owner = $this->employee(EmployeeRole::Owner);
        $staff = $this->employee(EmployeeRole::Staff);
        app(EmployeePermissionOverrideService::class)->change(
            $staff->employee,
            AccountingPermission::ViewPayables->value,
            EmployeePermissionEffect::Allow,
            'Supplier Payables navigation link test.',
            $owner,
        );

        $this->actingAs($staff)->get(SupplierPayables::getUrl())
            ->assertOk()
            ->assertSee('Supplier Payables')
            ->assertSee('href="'.SupplierPayables::getUrl().'"', false)
            ->assertDontSee('href="'.AccountingHome::getUrl().'"', false);
    }

    public function test_payment_and_bill_search_options_show_references_and_remaining_balances(): void
    {
        $this->withoutVite();
        $owner = $this->employee(EmployeeRole::Owner);
        $book = AccountingBook::query()->firstOrFail();
        $supplier = Supplier::factory()->create(['name' => 'Searchable AP Supplier']);
        $service = app(AccountsPayableService::class);

        $bill = $service->createDraftBill([
            'accounting_book_id' => $book->id,
            'supplier_id' => $supplier->id,
            'bill_number' => 'INV-AP-740',
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency' => $book->currency,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['description' => 'AP allocation test', 'quantity' => '1', 'unit_cost' => '125.00', 'vat_rate' => '0']],
        ], $owner);
        $payment = $service->recordDraftPayment([
            'accounting_book_id' => $book->id,
            'supplier_id' => $supplier->id,
            'payment_date' => now()->toDateString(),
            'currency' => $book->currency,
            'amount' => '100.00',
            'reference' => 'PAY-AP-740',
            'idempotency_key' => (string) Str::uuid(),
        ], $owner);

        $this->actingAs($owner);

        Livewire::test(SupplierPayables::class)
            ->set('bookId', (string) $book->id)
            ->set('supplierId', (string) $supplier->id)
            ->assertSee('Supplier statement balance')
            ->assertSee('Supplier aging')
            ->assertSee('Supplier bills')
            ->assertSee('Supplier payments')
            ->assertSee('Credits and debit notes')
            ->set('allocationPaymentSearch', 'PAY-AP-740')
            ->assertSee('PAY-AP-740')
            ->assertSee('100.00 available')
            ->call('selectAllocationPayment', $payment->id)
            ->set('allocationBillSearch', 'INV-AP-740')
            ->assertSee('INV-AP-740')
            ->assertSee('Outstanding '.$book->currency.' 125.00')
            ->call('selectAllocationBill', $bill->id)
            ->set('allocationAmount', '100.00')
            ->call('allocatePayment')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('accounting_supplier_payment_allocations', [
            'payment_id' => $payment->id,
            'bill_id' => $bill->id,
            'amount' => '100.00',
        ]);
        $this->assertFalse($book->refresh()->posting_enabled);
    }

    public function test_payables_view_permission_does_not_expose_creation_or_allocation_forms(): void
    {
        $this->withoutVite();
        $owner = $this->employee(EmployeeRole::Owner);
        $staff = $this->employee(EmployeeRole::Staff);
        app(EmployeePermissionOverrideService::class)->change(
            $staff->employee,
            AccountingPermission::ViewPayables->value,
            EmployeePermissionEffect::Allow,
            'Supplier Payables view-only interface test.',
            $owner,
        );

        $this->actingAs($staff);

        Livewire::test(SupplierPayables::class)
            ->assertSee('Supplier Payables')
            ->assertDontSee('New supplier bill')
            ->assertDontSee('New supplier payment')
            ->assertDontSee('Credit or debit note')
            ->assertDontSee('Payment allocation');
    }

    public function test_saved_payment_draft_appears_on_the_page_immediately(): void
    {
        $this->withoutVite();
        $owner = $this->employee(EmployeeRole::Owner);
        $book = AccountingBook::query()->firstOrFail();
        $supplier = Supplier::factory()->create();

        $this->actingAs($owner);

        Livewire::test(SupplierPayables::class)
            ->set('bookId', (string) $book->id)
            ->set('supplierId', (string) $supplier->id)
            ->set('paymentDate', now()->toDateString())
            ->set('paymentAmount', '47.50')
            ->set('paymentReference', 'PAY-IMMEDIATE-901')
            ->call('savePayment')
            ->assertHasNoErrors()
            ->assertSee('PAY-IMMEDIATE-901')
            ->assertSee('47.50');

        $this->assertFalse($book->refresh()->posting_enabled);
    }

    private function employee(EmployeeRole $role): User
    {
        $user = User::factory()->create(['email' => Str::uuid().'@techpointzone.com']);
        Employee::factory()->for($user)->create(['role' => $role, 'status' => true, 'email' => $user->email]);

        return $user;
    }
}
