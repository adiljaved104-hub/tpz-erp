<?php

namespace Tests\Feature\Phase1C;

use App\Actions\Products\ExportProducts;
use App\Contracts\ProductPermissionResolver;
use App\DTOs\Products\ProductExportData;
use App\Enums\EmployeeRole;
use App\Enums\ProductPermission;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class ProductExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_export_includes_both_prices_and_safe_activity_metadata(): void
    {
        Product::factory()->create(['cost_price' => '12.3456', 'selling_price' => '20.00']);
        $owner = $this->user(EmployeeRole::Owner);
        $csv = $this->send(app(ExportProducts::class)->handle(new ProductExportData(status: 'active'), $owner));

        $header = str_getcsv(strtok($csv, "\n"));
        $this->assertContains('cost_price', $header);
        $this->assertContains('selling_price', $header);

        $log = ActivityLog::query()->where('event', 'product.exported')->firstOrFail();
        $this->assertSame(1, $log->properties['exported_row_count']);
        $this->assertSame(['status' => 'active'], $log->properties['filters']);
        $this->assertContains('cost_price', $log->properties['included_fields']);
        $this->assertStringNotContainsString('12.3456', json_encode($log->properties));
        $this->assertStringNotContainsString('20.00', json_encode($log->properties));
    }

    public function test_admin_and_manager_exports_never_select_cost_price(): void
    {
        Product::factory()->create(['cost_price' => '12.3456', 'selling_price' => '20.00']);

        foreach ([EmployeeRole::Admin, EmployeeRole::Manager] as $role) {
            $queries = [];
            DB::listen(function (QueryExecuted $query) use (&$queries): void {
                if (str_contains(strtolower($query->sql), 'products')) {
                    $queries[] = strtolower($query->sql);
                }
            });
            $csv = $this->send(app(ExportProducts::class)->handle(new ProductExportData, $this->user($role)));
            $header = str_getcsv(strtok($csv, "\n"));

            $this->assertContains('selling_price', $header);
            $this->assertNotContains('cost_price', $header);
            $this->assertNotEmpty($queries);
            $this->assertStringNotContainsString('cost_price', implode(' ', $queries));
        }
    }

    public function test_staff_cannot_export_by_default(): void
    {
        $this->expectException(AuthorizationException::class);
        app(ExportProducts::class)->handle(new ProductExportData, $this->user(EmployeeRole::Staff));
    }

    public function test_future_employee_grant_uses_the_same_export_workflow(): void
    {
        Product::factory()->create(['cost_price' => '12.3456', 'selling_price' => '20.00']);
        $staff = $this->user(EmployeeRole::Staff);
        $this->app->instance(ProductPermissionResolver::class, new class implements ProductPermissionResolver
        {
            public function allows(User $user, ProductPermission $permission, ?Product $product = null): bool
            {
                return in_array($permission, [
                    ProductPermission::View,
                    ProductPermission::ViewSellingPrice,
                    ProductPermission::Export,
                ], true);
            }
        });

        $header = str_getcsv(strtok($this->send(
            app(ExportProducts::class)->handle(new ProductExportData, $staff),
        ), "\n"));

        $this->assertContains('selling_price', $header);
        $this->assertNotContains('cost_price', $header);
    }

    private function send(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
