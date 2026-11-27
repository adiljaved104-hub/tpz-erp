<?php

use App\Enums\EmployeeRole;
use App\Models\CompanyProfile;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\InvoiceSetting;
use App\Models\User;
use App\Services\Invoices\TaxInvoiceService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
if (! $app->environment('testing') || $connection->getDriverName() !== 'mysql'
    || $connection->getDatabaseName() !== 'tpz_invoice_footer_rehearsal_test'
    || Schema::hasTable('migrations')) {
    throw new RuntimeException('Expected a new empty disposable footer rehearsal schema.');
}
Artisan::call('migrate:install');
$migrator = app('migrator');
$migrations = $migrator->getMigrationFiles([database_path('migrations')]);
$name = '2026_11_27_120000_add_invoice_footer_branding_settings';
unset($migrations[$name]);
$migrator->runPending($migrations, []);
if (Schema::hasColumn('invoice_settings', 'footer_branding')) {
    throw new RuntimeException('The rehearsal must start before the new column exists.');
}
$owner = User::factory()->create();
Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['status' => true]);
$staff = Employee::factory()->role(EmployeeRole::Staff)->create(['status' => true]);
EmployeePermissionOverride::query()->create(['employee_id' => $staff->id, 'permission_key' => 'invoice.view', 'effect' => 'allow', 'granted_by_user_id' => $owner->id, 'reason' => 'Historical permission fixture']);
CompanyProfile::query()->create(['id' => 1, 'company_name_en' => 'Historical rehearsal company', 'trn' => '100000000000001']);
InvoiceSetting::query()->create(['id' => 1, 'invoice_prefix' => 'REHEARSAL', 'starting_number' => 500, 'vat_rate' => '5.00', 'terms_en' => 'Historical terms.', 'terms_ar' => 'شروط تاريخية.']);
app(TaxInvoiceService::class)->create([
    'customer_name' => 'Disposable historical fixture', 'customer_address' => 'Test address',
    'order_reference' => 'REHEARSAL-ONLY', 'invoice_date' => '2026-09-01', 'idempotency_key' => (string) Str::uuid(),
    'items' => [['description' => 'Historical product', 'quantity' => 2, 'unit_price_including_vat' => '105.00']],
], $owner->refresh());
$tables = ['invoice_settings', 'tax_invoices', 'tax_invoice_items', 'activity_logs', 'employee_permission_overrides', 'reference_sequences', 'employees', 'users'];
$snapshot = function () use ($tables): array {
    $result = [];
    foreach ($tables as $table) {
        $rows = DB::table($table)->get()->map(function ($row): array {
            $data = (array) $row;
            if (array_key_exists('footer_branding', $data)) {
                if ($data['footer_branding'] !== null) {
                    throw new RuntimeException('Historical settings must remain NULL / disabled.');
                }
                unset($data['footer_branding']);
            }
            ksort($data);

            return $data;
        })->all();
        sort($rows);
        $result[$table] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    return $result;
};
$before = $snapshot();
if (Artisan::call('migrate', ['--path' => ['database/migrations/'.$name.'.php'], '--force' => true, '--no-interaction' => true]) !== 0) {
    throw new RuntimeException(Artisan::output());
}
if ($before !== $snapshot() || ! Schema::hasColumn('invoice_settings', 'footer_branding')) {
    throw new RuntimeException('Historical data changed during the additive footer migration.');
}
echo 'PASS: nullable footer column; existing settings, invoices, lines, audit, permissions, reference sequences and employee records unchanged.'.PHP_EOL;
