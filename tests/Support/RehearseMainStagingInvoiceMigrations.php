<?php

use App\Enums\EmployeeRole;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDriverName() !== 'mysql'
    || DB::connection()->getDatabaseName() !== 'tpz_main_staging_rehearsal_test'
    || Schema::hasTable('migrations')) {
    throw new RuntimeException('Expected an empty, isolated Main/Staging rehearsal schema.');
}
$pending = [
    '2026_11_27_120000_add_invoice_footer_branding_settings',
    '2026_11_27_150000_create_tax_invoice_payment_events',
];
Artisan::call('migrate:install');
$migrator = app('migrator');
$files = $migrator->getMigrationFiles([database_path('migrations')]);
foreach ($pending as $name) {
    unset($files[$name]);
}
$migrator->runPending($files, []);
if (Schema::hasColumn('invoice_settings', 'footer_branding') || Schema::hasTable('tax_invoice_payment_events')) {
    throw new RuntimeException('Rehearsal baseline unexpectedly contains a new invoice structure.');
}
$owner = User::factory()->create();
Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['status' => true]);
$staff = Employee::factory()->role(EmployeeRole::Staff)->create(['status' => true]);
EmployeePermissionOverride::query()->create(['employee_id' => $staff->id, 'permission_key' => 'invoice.view', 'effect' => 'allow', 'granted_by_user_id' => $owner->id, 'reason' => 'Historical permission fixture']);
DB::table('invoice_settings')->insert(['id' => 1, 'invoice_prefix' => 'LEGACY', 'starting_number' => 700, 'vat_rate' => '5.00', 'terms_en' => 'Historical configured terms.', 'terms_ar' => 'شروط تاريخية.', 'created_at' => now(), 'updated_at' => now()]);
$invoiceId = DB::table('tax_invoices')->insertGetId([
    'invoice_number' => 'LEGACY 699', 'order_reference' => 'HISTORICAL-ORDER', 'invoice_date' => '2026-09-01',
    'customer_name' => 'Disposable historical fixture', 'customer_address' => 'Test address',
    'vat_rate' => '5.00', 'subtotal_excluding_vat' => '100.00', 'vat_amount' => '5.00', 'grand_total' => '105.00',
    'seller_snapshot' => json_encode(['company_name_en' => 'Historical company', 'trn' => '100000000000001']),
    'terms_en_snapshot' => 'Historical configured terms.', 'status' => 'issued',
    'created_by_user_id' => $owner->id, 'issued_at' => now(), 'idempotency_key' => (string) Str::uuid(),
    'verification_token' => bin2hex(random_bytes(32)), 'created_at' => now(), 'updated_at' => now(),
]);
DB::table('tax_invoice_items')->insert([
    'tax_invoice_id' => $invoiceId, 'description' => 'Historical product', 'quantity' => 1,
    'unit_price_including_vat' => '105.00', 'subtotal_excluding_vat' => '100.00',
    'vat_amount' => '5.00', 'total_including_vat' => '105.00', 'line_number' => 1, 'created_at' => now(), 'updated_at' => now(),
]);
$tables = ['invoice_settings', 'tax_invoices', 'tax_invoice_items', 'quotations', 'quotation_items', 'activity_logs', 'employee_permission_overrides', 'reference_sequences', 'users', 'employees', 'accounting_journals', 'accounting_journal_lines'];
$snapshot = function () use ($tables): array {
    $result = [];
    foreach ($tables as $table) {
        $rows = DB::table($table)->get()->map(function ($row): array {
            $data = (array) $row;
            if (array_key_exists('footer_branding', $data)) {
                if ($data['footer_branding'] !== null) {
                    throw new RuntimeException('Historical settings must not be activated or backfilled.');
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
$paths = array_map(fn (string $name): string => 'database/migrations/'.$name.'.php', $pending);
if (Artisan::call('migrate', ['--path' => $paths, '--force' => true, '--no-interaction' => true]) !== 0
    || $before !== $snapshot() || DB::table('tax_invoice_payment_events')->count() !== 0
    || config('accounting.posting_activation_approved') !== false) {
    throw new RuntimeException('Main/Staging migration preservation gate failed.');
}
echo 'PASS: exactly two additive invoice migrations; historical records, permissions, numbering and accounting rows unchanged; no payment backfill; posting disabled.'.PHP_EOL;
