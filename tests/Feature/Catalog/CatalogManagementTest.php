<?php

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\CreateProductBrand;
use App\Actions\Catalog\CreateProductCategory;
use App\Actions\Catalog\RenameProductBrand;
use App\Actions\Catalog\SetProductBrandStatus;
use App\DTOs\Catalog\ChangeCatalogStatusData;
use App\DTOs\Catalog\CreateCatalogItemData;
use App\DTOs\Catalog\RenameCatalogItemData;
use App\Enums\CatalogPermission;
use App\Enums\EmployeeRole;
use App\Exceptions\HardDeletionProhibitedException;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\ProductBrand;
use App\Models\User;
use App\Services\Authorization\CatalogAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CatalogManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_permission_matrix_matches_approved_defaults(): void
    {
        $authorization = app(CatalogAuthorization::class);
        foreach (CatalogPermission::cases() as $permission) {
            $this->assertTrue($authorization->allows($this->user(EmployeeRole::Owner), $permission));
            $this->assertTrue($authorization->allows($this->user(EmployeeRole::Admin), $permission));
            $this->assertSame(in_array($permission, [CatalogPermission::BrandView, CatalogPermission::CategoryView], true), $authorization->allows($this->user(EmployeeRole::Manager), $permission));
            $this->assertFalse($authorization->allows($this->user(EmployeeRole::Staff), $permission));
        }
    }

    public function test_create_rename_and_status_actions_normalize_uniquely_and_log_safely(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $brand = app(CreateProductBrand::class)->handle(new CreateCatalogItemData('  HP  '), $owner);
        $category = app(CreateProductCategory::class)->handle(new CreateCatalogItemData('All  in   One'), $owner);
        $this->assertSame('hp', $brand->normalized_name);
        $this->assertSame('all in one', $category->normalized_name);

        try {
            app(CreateProductBrand::class)->handle(new CreateCatalogItemData(' hp '), $owner);
            $this->fail('Logical duplicate must be rejected.');
        } catch (ValidationException) {
            $this->assertSame(1, ProductBrand::query()->count());
        }

        app(RenameProductBrand::class)->handle($brand, new RenameCatalogItemData('Hewlett-Packard'), $owner);
        app(SetProductBrandStatus::class)->handle($brand, new ChangeCatalogStatusData(false, 'Not currently used'), $owner);
        $events = ActivityLog::query()->where('subject_type', 'product_brand')->pluck('event')->all();
        $this->assertContains('catalog.brand.created', $events);
        $this->assertContains('catalog.brand.renamed', $events);
        $this->assertContains('catalog.brand.status_changed', $events);
        $this->assertStringNotContainsString('price', strtolower((string) ActivityLog::query()->where('subject_type', 'product_brand')->get()->toJson()));
    }

    public function test_manager_cannot_manage_and_hard_deletion_is_rejected(): void
    {
        $manager = $this->user(EmployeeRole::Manager);
        try {
            app(CreateProductBrand::class)->handle(new CreateCatalogItemData('Denied'), $manager);
            $this->fail('Manager must not manage catalog data.');
        } catch (AuthorizationException) {
            $this->assertDatabaseMissing('product_brands', ['normalized_name' => 'denied']);
        }

        $brand = ProductBrand::factory()->create();
        $this->expectException(HardDeletionProhibitedException::class);
        $brand->delete();
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
