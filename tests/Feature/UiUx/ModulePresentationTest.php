<?php

namespace Tests\Feature\UiUx;

use App\Enums\EmployeeRole;
use App\Enums\OrderStatus;
use App\Filament\Resources\Complaints\Pages\ListComplaints;
use App\Filament\Resources\Complaints\Widgets\ComplaintStats;
use App\Filament\Resources\CustomerReturns\Pages\ListCustomerReturns;
use App\Filament\Resources\CustomerReturns\Widgets\CustomerReturnStats;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Widgets\OrderStats;
use App\Filament\Resources\Purchases\Pages\ListPurchases;
use App\Filament\Resources\SafetClaims\Pages\ListSafetClaims;
use App\Filament\Resources\SafetClaims\Widgets\SafetClaimStats;
use App\Filament\Resources\WarrantyRepairs\Pages\ListWarrantyRepairs;
use App\Filament\Resources\WarrantyRepairs\Widgets\WarrantyRepairStats;
use App\Filament\Widgets\PendingPurchaseReceivingStats;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class ModulePresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_high_frequency_list_pages_render_compact_authorized_summary_widgets(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->actingAs($owner);

        Livewire::test(ListOrders::class)->assertOk();
        Livewire::test(ListPurchases::class)->assertOk();
        Livewire::test(ListCustomerReturns::class)->assertOk();
        Livewire::test(ListSafetClaims::class)->assertOk();
        Livewire::test(ListWarrantyRepairs::class)->assertOk();
        Livewire::test(ListComplaints::class)->assertOk();

        Livewire::test(OrderStats::class)->assertSee('Orders Today');
        Livewire::test(PendingPurchaseReceivingStats::class)->assertSee('Outstanding Units');
        Livewire::test(CustomerReturnStats::class)->assertSee('Awaiting QC');
        Livewire::test(SafetClaimStats::class)->assertSee('Needs Filing');
        Livewire::test(WarrantyRepairStats::class)->assertSee('Waiting for Parts');
        Livewire::test(ComplaintStats::class)->assertSee('Awaiting Response');
    }

    public function test_order_widget_uses_authorized_query_and_existing_quantity_semantics(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $warehouse = Warehouse::query()->where('is_default', true)->sole();
        $product = Product::factory()->create();
        $order = Order::query()->create([
            'reference' => 'SO-2026-UI0001',
            'status' => OrderStatus::Reserved,
            'warehouse_id' => $warehouse->id,
            'order_date' => today(),
            'idempotency_key' => (string) Str::uuid(),
            'created_by_user_id' => $owner->id,
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'ordered_quantity' => 4,
            'selling_price' => '100.00',
            'line_total' => '400.00',
        ]);

        $this->actingAs($owner);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $stats = $this->stats(OrderStats::class);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame(1, $stats['Orders Today']);
        $this->assertSame(1, $stats['Pending / Reserved']);
        $this->assertSame(4, $stats['Units']);
        $this->assertArrayNotHasKey('Sales Value', $stats);
        $this->assertLessThanOrEqual(3, $queryCount);

        $staff = $this->user(EmployeeRole::Staff);
        $this->actingAs($staff);
        $scoped = $this->stats(OrderStats::class);
        $this->assertSame(0, $scoped['Orders Today']);
        $this->assertSame(0, $scoped['Units']);
    }

    public function test_inactive_employee_cannot_render_operational_widget(): void
    {
        $user = $this->user(EmployeeRole::Staff, active: false);
        $this->actingAs($user);

        $this->assertFalse(OrderStats::canView());
    }

    /** @return array<string, int> */
    private function stats(string $widget): array
    {
        $method = new ReflectionMethod($widget, 'getStats');

        return collect($method->invoke(app($widget)))
            ->mapWithKeys(fn ($stat): array => [(string) $stat->getLabel() => (int) $stat->getValue()])
            ->all();
    }

    private function user(EmployeeRole $role, bool $active = true): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create([
            'email' => $user->email,
            'status' => $active,
        ]);

        return $user->refresh();
    }
}
