<?php

namespace Tests\Feature\Phase1B;

use App\Exceptions\HardDeletionProhibitedException;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardDeletionProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_cannot_be_deleted_through_eloquent(): void
    {
        $supplier = Supplier::factory()->create();

        $this->expectException(HardDeletionProhibitedException::class);
        $supplier->delete();
    }

    public function test_warehouse_cannot_be_deleted_through_eloquent(): void
    {
        $warehouse = Warehouse::factory()->create();

        $this->expectException(HardDeletionProhibitedException::class);
        $warehouse->delete();
    }
}
