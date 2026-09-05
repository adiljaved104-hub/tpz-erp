<?php

namespace Tests\Feature\Phase1C;

use App\Enums\EmployeeRole;
use App\Exceptions\ProductHardDeletionProhibitedException;
use App\Models\Employee;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDeletionProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_and_observer_prohibit_hard_deletion(): void
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        $product = Product::factory()->create();

        $this->assertFalse($owner->can('delete', $product));
        $this->expectException(ProductHardDeletionProhibitedException::class);
        $product->delete();
    }
}
