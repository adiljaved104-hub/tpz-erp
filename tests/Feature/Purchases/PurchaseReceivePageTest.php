<?php

namespace Tests\Feature\Purchases;

use App\Enums\EmployeeRole;
use App\Enums\PurchaseStatus;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\Employee;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseReceivePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_owner_can_open_receive_page_for_approved_purchase_without_posting_a_grn(): void
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create();
        $purchase = Purchase::factory()->create(['status' => PurchaseStatus::Approved]);
        PurchaseItem::factory()->for($purchase)->for(Product::factory())->create([
            'ordered_quantity' => 2,
            'received_quantity' => 0,
        ]);

        $this->actingAs($owner)
            ->get(PurchaseResource::getUrl('receive', ['record' => $purchase]))
            ->assertOk();

        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertDatabaseCount('purchase_receipt_items', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_receive_page_blocks_ineligible_statuses_and_unauthorized_users(): void
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create();
        $staff = User::factory()->create();
        Employee::factory()->for($staff)->role(EmployeeRole::Staff)->create();
        $purchase = Purchase::factory()->create();

        foreach ([PurchaseStatus::Draft, PurchaseStatus::Closed, PurchaseStatus::Cancelled] as $status) {
            $purchase->forceFill(['status' => $status])->save();

            $this->actingAs($owner)
                ->get(PurchaseResource::getUrl('receive', ['record' => $purchase]))
                ->assertForbidden();
        }

        $purchase->forceFill(['status' => PurchaseStatus::Approved])->save();

        $this->actingAs($staff)
            ->get(PurchaseResource::getUrl('receive', ['record' => $purchase]))
            ->assertForbidden();
    }
}
